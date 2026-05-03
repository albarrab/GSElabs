from io import StringIO

import numpy as np
import pandas as pd
from flask import Flask, jsonify, request
from sklearn.compose import ColumnTransformer
from sklearn.ensemble import RandomForestClassifier
from sklearn.impute import SimpleImputer
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import accuracy_score, f1_score, precision_score, recall_score, confusion_matrix
from sklearn.model_selection import train_test_split
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import FunctionTransformer, OneHotEncoder, StandardScaler
from sklearn.svm import LinearSVC

app = Flask(__name__)


def normalize_column_name(value):
    text = str(value)
    # Strip UTF-8 BOM and surrounding whitespace, then compare case-insensitively.
    return text.replace("\ufeff", "").strip().lower()


def to_binary_label(value):
    text = str(value).strip().lower()
    benign_tokens = {"benign", "normal", "0", "false", "no", "legitimate"}
    if text in benign_tokens:
        return 0
    return 1


def evaluate_model(model, x_train, x_test, y_train, y_test):
    model.fit(x_train, y_train)
    y_pred = model.predict(x_test)

    tn, fp, fn, tp = confusion_matrix(y_test, y_pred, labels=[0, 1]).ravel()
    fpr = fp / (fp + tn) if (fp + tn) > 0 else 0.0

    return {
        "accuracy": float(accuracy_score(y_test, y_pred)),
        "precision": float(precision_score(y_test, y_pred, zero_division=0)),
        "recall": float(recall_score(y_test, y_pred, zero_division=0)),
        "f1": float(f1_score(y_test, y_pred, zero_division=0)),
        "false_positive_rate": float(fpr),
    }


def load_sampled_dataframe(file_storage, max_rows=60000, chunk_size=20000, seed=42):
    # Read uploaded payload in a cPanel/Werkzeug-compatible way, then stream via StringIO.
    file_storage.stream.seek(0)
    raw = file_storage.stream.read()
    if isinstance(raw, bytes):
        text = raw.decode("utf-8", errors="ignore")
    else:
        text = str(raw)
    text_stream = StringIO(text)
    rng = np.random.default_rng(seed)

    sample_df = None
    total_rows = 0
    for chunk in pd.read_csv(text_stream, chunksize=chunk_size):
        if chunk.shape[0] == 0:
            continue
        total_rows += int(chunk.shape[0])
        chunk = chunk.copy()
        chunk["__sample_priority__"] = rng.random(chunk.shape[0])
        if sample_df is None:
            sample_df = chunk
        else:
            sample_df = pd.concat([sample_df, chunk], ignore_index=True)
            if sample_df.shape[0] > max_rows:
                sample_df = sample_df.nsmallest(max_rows, "__sample_priority__")
    if sample_df is None:
        return pd.DataFrame(), 0
    sample_df = sample_df.drop(columns=["__sample_priority__"])
    sample_df.reset_index(drop=True, inplace=True)
    return sample_df, total_rows


@app.errorhandler(Exception)
def unhandled_error(exc):
    app.logger.exception("Unhandled exception in ML service")
    return jsonify({"error": f"ML service internal error: {exc}"}), 500


@app.get("/health")
def health():
    return jsonify({"status": "ok"})


@app.post("/analyze")
def analyze():
    file = request.files.get("file")
    if not file:
        return jsonify({"error": "No CSV file uploaded."}), 400

    target_col_input = request.form.get("target_column", "Label")

    try:
        max_rows_for_training = 60000
        df, total_rows = load_sampled_dataframe(file, max_rows=max_rows_for_training)
    except Exception as exc:
        return jsonify({"error": f"Failed to parse CSV: {exc}"}), 400

    if total_rows == 0:
        return jsonify({"error": "CSV appears empty after parsing."}), 400

    normalized_to_original = {}
    for col in df.columns:
        normalized_to_original[normalize_column_name(col)] = col

    normalized_target = normalize_column_name(target_col_input)
    target_col = normalized_to_original.get(normalized_target)
    if target_col is None:
        sample_cols = [str(col) for col in df.columns[:12]]
        return (
            jsonify(
                {
                    "error": (
                        f"Target column '{target_col_input}' not found in dataset. "
                        f"Detected columns include: {sample_cols}"
                    )
                }
            ),
            400,
        )

    if total_rows < 50:
        return jsonify({"error": "Dataset is too small for reliable evaluation. Upload at least 50 rows."}), 400

    sampled_rows = None
    if total_rows > max_rows_for_training:
        sampled_rows = int(df.shape[0])

    y = df[target_col].apply(to_binary_label)
    class_counts = y.value_counts(dropna=False).to_dict()
    if y.nunique() < 2:
        if 0 in class_counts:
            class_summary = f"normal(0)={int(class_counts.get(0, 0))}, malicious(1)={int(class_counts.get(1, 0))}"
        else:
            class_summary = f"normal(0)={int(class_counts.get('0', 0))}, malicious(1)={int(class_counts.get('1', 0))}"
        return (
            jsonify(
                {
                    "error": (
                        "Dataset contains only one class after binary mapping, so binary classification cannot be trained. "
                        f"Detected class counts: {class_summary}. "
                        "Use a dataset (or merge files) that includes both normal and malicious rows."
                    )
                }
            ),
            400,
        )
    x = df.drop(columns=[target_col])

    # Some IDS datasets contain inf/-inf or overflowed numeric values; treat as missing.
    x = x.replace([np.inf, -np.inf], np.nan)

    # Remove constant columns to reduce model noise.
    nunique = x.nunique(dropna=False)
    x = x.loc[:, nunique > 1]

    if x.shape[1] == 0:
        return jsonify({"error": "No usable feature columns found after preprocessing."}), 400

    numeric_features = x.select_dtypes(include=[np.number]).columns.tolist()
    categorical_features = [col for col in x.columns if col not in numeric_features]

    # Some datasets contain mixed types in the same categorical column (e.g., ints + strings),
    # which breaks OneHotEncoder. Normalize non-null categorical values to strings.
    if categorical_features:
        cat_df = x[categorical_features]
        x[categorical_features] = cat_df.where(cat_df.isna(), cat_df.astype(str))

    # Avoid one-hot explosions from identifier-like columns with huge cardinality.
    if categorical_features:
        cat_unique = x[categorical_features].nunique(dropna=True)
        high_cardinality = [c for c in categorical_features if int(cat_unique.get(c, 0)) > 2000]
        if high_cardinality:
            x = x.drop(columns=high_cardinality)
            categorical_features = [c for c in categorical_features if c not in high_cardinality]
            numeric_features = [c for c in numeric_features if c in x.columns]

    numeric_pipeline = Pipeline(
        steps=[
            ("imputer", SimpleImputer(strategy="median")),
            ("scaler", StandardScaler()),
        ]
    )

    categorical_pipeline = Pipeline(
        steps=[
            (
                "to_string",
                FunctionTransformer(
                    lambda arr: pd.DataFrame(arr).fillna("__missing__").astype(str).to_numpy(),
                    validate=False,
                ),
            ),
            ("onehot", OneHotEncoder(handle_unknown="ignore")),
        ]
    )

    preprocess = ColumnTransformer(
        transformers=[
            ("num", numeric_pipeline, numeric_features),
            ("cat", categorical_pipeline, categorical_features),
        ]
    )

    x_train, x_test, y_train, y_test = train_test_split(
        x,
        y,
        test_size=0.25,
        random_state=42,
        stratify=y if y.nunique() > 1 else None,
    )

    models = {
        "Logistic Regression": LogisticRegression(max_iter=300),
        "Random Forest": RandomForestClassifier(n_estimators=80, random_state=42, n_jobs=1),
        "Support Vector Machine": LinearSVC(dual="auto", max_iter=3000),
    }

    results = {}
    try:
        for name, clf in models.items():
            pipeline = Pipeline(steps=[("preprocess", preprocess), ("classifier", clf)])
            results[name] = evaluate_model(pipeline, x_train, x_test, y_train, y_test)
    except Exception as exc:
        return jsonify({"error": f"Dataset preprocessing/model training failed: {exc}"}), 400

    best_model = max(results.items(), key=lambda kv: kv[1]["f1"])

    return jsonify(
        {
            "rows": int(total_rows),
            "sampled_rows": sampled_rows,
            "features_used": int(x.shape[1]),
            "target_column": str(target_col),
            "results": results,
            "best_model": {
                "name": best_model[0],
                "metrics": best_model[1],
            },
        }
    )


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=5000)