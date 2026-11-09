import sys
src=open('sim.py').read().split("res={}")[0]
exec(src)
import numpy as np
mu=X.mean(0)
def codes(Z): return np.where(Z>0,1,-1).astype(np.float32)
A=rot(D,D,1); C=codes((X-mu)@A); Cq=codes((Q-mu)@A)
K=int(round(np.sqrt(N)*1.0))
# spherical k-means on a sample
samp=X[rng.choice(N,min(N,40000),replace=False)]
cent=samp[rng.choice(len(samp),K,replace=False)].copy()
for it in range(12):
    a=np.argmax(samp@cent.T,1)
    for k in range(K):
        m=samp[a==k]
        if len(m): cent[k]=m.mean(0)
    cent/=np.linalg.norm(cent,axis=1,keepdims=True)+1e-9
assign=np.argmax(X@cent.T,1)
sizes=np.bincount(assign,minlength=K); print(f'dataset={ds} N={N} K={K} cluster size mean={sizes.mean():.0f} max={sizes.max()}')
print(' '*38+'            '+' '.join(f'{n:>9d}' for n in NPRE))
for nprobe in [1,4,8,16,32]:
    cs=Q@cent.T; probe=np.argsort(-cs,1)[:,:nprobe]
    scanned=np.mean([sizes[p].sum() for p in probe])/N
    def pf(ids):
        P=Cq[ids]@C.T
        mask=np.zeros(P.shape,bool)
        for j,q in enumerate(ids): mask[j]=np.isin(assign,probe[q])
        P[~mask]=-1e8
        return P
    evaluate(f'IVF K={K} nprobe={nprobe} + xor{D}',pf,scanned)
