# Assessment Results Pack (Labs 1-10) - Final Actual Hardware Results

## Proposed Paper Title

**From Compiler Flags to Carbon-Aware Scheduling: A Cross-Lab Energy Reduction Study for Green Software Engineering**

## Method Note

This pack consolidates results from the full Lab 1-10 workflow using **actual hardware runs** recorded in `gse_labs_actual/`.
Values were updated from measured logs (not draft estimates).

## A) Completion Index (Labs 1-10)

| Lab | Completion File |
|---|---|
| Lab01 | `LAB01_COMPLETION.md` |
| Lab02 | `LAB02_COMPLETION.md` |
| Lab03 | `LAB03_COMPLETION.md` |
| Lab04 | `LAB04_COMPLETION.md` |
| Lab05 | `LAB05_COMPLETION.md` |
| Lab06 | `LAB06_COMPLETION.md` |
| Lab07 | `LAB07_COMPLETION.md` |
| Lab08 | `LAB08_COMPLETION.md` |
| Lab09 | `LAB09_COMPLETION.md` |
| Lab10 | `LAB10_COMPLETION.md` |

## B) Baseline Reference (Assessment Requirement)

Configuration:
- Input size: `N=3600`
- Compiler: GNU `gcc`
- Optimization: `-O0`

Measured baseline run:

| Run | Time (s) | Energy (J) |
|---|---:|---:|
| gcc -O0 | 187.53 | 6687.199251 |

Baseline values used for % calculations:
- Time baseline: **187.53 s**
- Energy baseline: **6687.199251 J**

Formula:

`% reduction = ((baseline - new) / baseline) * 100`

## C) Cross-Lab Results Matrix (Actual)

| Lab Theme | Representative Intervention | Time (s) | Energy (J) | Carbon (gCO2e) | Reduction |
|---|---|---:|---:|---:|---:|
| Lab01 Linux/Git | Workflow setup and reproducible execution | - | - | - | Process enablement |
| Lab02 C + compiler basics | Baseline C implementation (`gcc -O0`) | 187.53 | 6687.199251 | 0.328 (Gov factor) | Baseline |
| Lab03 Optimisation flags | `gcc -O1` at same `N=3600` | 55.12 | 1919.876860 | 0.094 (Gov factor) | 71.29% energy |
| Lab04 Language comparison | Fortran best (`ifx -O3`, `N=3600`) | 23.31 | 864.737655 | 0.043 (Gov factor) | 87.07% energy |
| Lab05 Libs + parallelism | C + MKL BLAS parallel (`icx`, OMP=6) | 0.19 | 13.304165 | 0.001 (Gov factor) | 99.80% energy |
| Lab06 Carbon estimation | Same energy under CI factor 67 vs 177 gCO2e/kWh | 187.53 | 6687.199251 | 0.124 (CI=67) | 62.15% carbon (CI effect) |
| Lab07 Variable CI scheduling | Region shift UK 221 to France 12 gCO2e/kWh | 187.53 | 6687.199251 | 0.022 (CI=12) | 94.57% carbon (CI effect) |
| Lab08 Evidence/reproducibility | Versioned measurements and traceability | - | - | - | Auditability gain |
| Lab09 Green AI | Width=256, batch=512 (fp32) | 0.011 | 0.999 | 0.000037 | 99.99% energy |
| Lab10 Hardware | Different lab hardware identified (i7-8700 vs i9-11900K) | - | - | - | Platform effect identified |

## D) Lab 09 Detail (AI)

### Batch-size sweep (width=256)

| Batch | Energy (J) | Time (s) | Accuracy | CO2 (g) |
|---|---:|---:|---:|---:|
| 1 | 26.976 | 0.468 | 0.968 | 0.000997 |
| 8 | 4.365 | 0.070 | 0.968 | 0.000161 |
| 32 | 2.695 | 0.038 | 0.968 | 0.000100 |
| 128 | 1.400 | 0.017 | 0.968 | 0.000052 |
| 512 | 0.999 | 0.011 | 0.968 | 0.000037 |

### Width sweep (batch=512)

| Width | Energy (J) | Time (s) | Accuracy | CO2 (g) |
|---|---:|---:|---:|---:|
| 256 | 0.999 | 0.011 | 0.968 | 0.000037 |
| 512 | 2.078 | 0.022 | 0.971 | 0.000077 |
| 1024 | 5.350 | 0.057 | 0.977 | 0.000198 |

### Precision sweep (width=512)

| Precision | Energy (J) | Time (s) | Accuracy | CO2 (g) |
|---|---:|---:|---:|---:|
| fp64 | 19.322 | 0.294 | 0.971 | 0.000585 |
| fp32 | 6.008 | 0.088 | 0.971 | 0.000182 |
| fp16 | 13.317 | 0.190 | 0.971 | 0.000403 |
| bf16 | 18.320 | 0.298 | 0.971 | 0.000555 |

## E) Report-Ready Insights

1. Compiler optimization alone (`-O1` vs `-O0`) delivered a major energy cut at fixed workload.
2. Language and toolchain choices (Fortran `ifx`) substantially reduced runtime and energy.
3. Library and parallel execution (MKL + OpenMP) produced the largest absolute energy savings.
4. Carbon-aware decisions (region/time) can reduce emissions even when Joules are unchanged.
5. AI inference energy is highly sensitive to batch size, model width, and numeric precision.

## F) Source Files Used (Actual)

- `gse_labs_actual/measureEnergy.txt`
- `gse_labs_actual/width256_run2.log`
- `gse_labs_actual/width512_run.log`
- `gse_labs_actual/width1024_run.log`
- `gse_labs_actual/precision_run.log`

