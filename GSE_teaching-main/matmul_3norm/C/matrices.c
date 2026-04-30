/*
 * matrix C = A*B, all of size N*N 
 * mkbane (2023-2026)
 * NB various ways to do a 2D array in the C programming language.
 * This example flattens the array and then calcs relevant position 
 * in the array from the (row, col) indices
 * This version outputs norm as indicator of result
 */
#include <stdio.h>
#include <stdlib.h>
#include <math.h>

#define DEFAULT_N 1024

// function prototypes
int fillRandom(float*, int);
int fillValues(float*, int, float);
int fillZeroes(float*, int);
void print2Darray(float*, int);
float getNorm(float*, int);

// main function
int main(int argc, char **argv) {
  int N;
  if(argc>1) {
    N = atoi(argv[1]);
    printf("Each array is %d by %d\n", N,N);
    if(argc>2) printf("(ignoring other parameters)");
  }
  else {
    N = DEFAULT_N;
    printf("Each array is %d by %d\n", N,N);
  }

  // allocate and initialise
  float *A, *B, *C;
  int arrayMemSize=sizeof(float)*N*N;
  //printf("each array has %f bytes\n", (double)arrayMemSize);
  A = (float*) malloc(arrayMemSize);
  B = (float*) malloc(arrayMemSize);
  C = (float*) malloc(arrayMemSize);
  // check none of these failed
  if (A==NULL || B==NULL || C==NULL) {
    printf("\n ***FAILURE*** to allocate arrays\n");
    abort();
  }
  printf("malloc complete\n");

  /*
   * using random values
   *
   *   // fix seed of PRNG
   *   srand(101);
   *   int rc_A = fillRandom(A, N);
   *   int rc_B = fillRandom(B, N);
  */
  
  int rc_A = fillValues(A, N, 1.0);
  int rc_B = fillValues(B, N, 0.5);
  int rc_C = fillZeroes(C, N);
  // check none of these failed
  if (rc_A != 0 || rc_B != 0 || rc_C !=0) {
    printf("failure to init arrays\n");
    abort();
  }

  printf("init complete\n");
#ifdef VERBOSE
  print2Darray(A, N);
  print2Darray(B, N);
#endif


  // naive matmul
  int pos_ij, pos_ik, pos_kj;
  for (int i=0; i<N; i++) {
    for (int j=0; j<N; j++) {
      for (int k=0; k<N; k++) {
	pos_ij = i+N*j;
	pos_ik = i+N*k;
	pos_kj = k+N*j;
	C[pos_ij] += A[pos_ik]*B[pos_kj];
      }
    }
  }

#ifdef VERBOSE
  printf("matrix C (final answer)");
  print2Darray(C, N);
#endif

  // output the norm
  printf("C has norm: %.3f\n", getNorm(C, N));

} // main

int fillRandom(float *array, int nSize) {
  // function to fill array with random numbers
  // note that rand returns integer so we divide by 1000 to get a float
  // and set zero as midpoint
  // where array is nSize * nSize
  // rc=0 upon success
  int rc = -1;
  for(int i=0; i<nSize*nSize; i++) {
    array[i] = (0.0001 * (float) rand()) - 0.00005*RAND_MAX;
  }
  rc = 0;
  return rc;
}

int fillValues(float *array, int nSize, float seed) {
  // function to fill array with known numbers between -seed and almost +seed
  // "almost" is to avoid symmetry for square matrices
  // rc=0 upon success
  int rc = -1;
  float step = 0.999*(2.0*seed / (float) (nSize * nSize - 1));
  for(int i=0; i<nSize*nSize; i++) {
    array[i] = -seed + (float) i * step;
  }
  rc = 0;
  return rc;
}

int fillZeroes(float *array, int nSize) {
  // function to fill array with zeroes
  // where array is nSize * nSize
  // rc=0 upon success
  int rc = -1;
  for(int i=0; i<nSize*nSize; i++) {
    array[i] = 0.00;
  }
  rc = 0;
  return rc;
}

void print2Darray(float *array, int nSize) {
  int row, col;
  // C stores data as col major
  for(int j=0; j<nSize; j++) {
    for(int i=0; i<nSize; i++) {
      printf("array[%d][%d]=%f\n",i,j,array[i+nSize*j]);
    }
  }
}

 float getNorm(float *array, int nSize) {
   // determine energy wise p=3 norm of the matrix
   // internal workings in double precision, return single precision
   int pos_ij;
   double sum = 0.0;
   double val;
   for (int i=0; i<nSize; i++) {
     for (int j=0; j<nSize; j++) {
       pos_ij = i+nSize*j;
       val = array[pos_ij];
       sum += fabs(val) * fabs(val) * fabs(val);
     }
   }
   float norm = pow(sum, 1.0/3.0);
   return norm;
 }
