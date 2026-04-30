
# Python implementation making use of numpy maths lib
# to form matrix C = A*B 
# where a vector implemented as Python list
# and a matrix is implemented as list of lists

import numpy
import sys
import math

## matrix dimension
if(len(sys.argv)>1):
    print(sys.argv[1:])
    n = int(sys.argv[1])
    # quietly ignore any other args
else:
    n = 1024
print("matmul for array of dimension ", n)

##
## fill A, B with numbers as per C example
## (NB could declare a function to make cleaner code)

## declare matrices as nested lists (using "list comprehension")
seed = 1.0
step = 0.999*(2.0*seed / (float) (n * n - 1));
A=[[row * n + col for col in range(n)] for row in range(n)]
i = 0.0
for row in range(0,n):
    for col in range(0,n):
        A[row][col] = -seed + i * step
        i = i+1.0
        ##VERBOSE: print("A[", row, "][", col, "]=", A[row][col])

seed = 0.5
step = 0.999*(2.0*seed / (float) (n * n - 1));
B=[[row * n + col for col in range(n)] for row in range(n)]
i = 0.0
for row in range(0,n):
    for col in range(0,n):
        B[row][col] = -seed + i * step
        i = i+1.0
        ##VERBOSE: print("B[", row, "][", col, "]=", B[row][col])

## multiplication
C = numpy.matmul(A,B)

##VERBOSE: # output result
##VERBOSE: for col in range(0,n):
##VERBOSE:     for row in range(0,n):
##VERBOSE:         print("C[", row, "][", col, "]=", C[row][col])

## output norm
sum = 0.0
for i in range(0,n):
    for j in range(0,n):
        sum += abs(C[i][j]) * abs(C[i][j]) * abs(C[i][j])
print("C has norm:", sum**(1.0/3.0))



