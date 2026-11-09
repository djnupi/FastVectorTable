import numpy as np, sys, time, json
rng=np.random.default_rng(0)
ds=sys.argv[1]
if ds=='random':
    N,D=160000,256
    X=rng.standard_normal((N,D)).astype(np.float32)
else:
    X=np.load('emb.npy'); N,D=X.shape
X/=np.linalg.norm(X,axis=1,keepdims=True)
NQ=150
qi=rng.choice(N,NQ,replace=False)
Q=X[qi]
NPRE=[10,50,200,500,2000]
MAXP=max(NPRE)

# ground truth (exclude self)
S=Q@X.T; S[np.arange(NQ),qi]=-9
gt=np.argpartition(-S,10,axis=1)[:,:10]
gts=np.take_along_axis(S,gt,1); ideal=np.sort(gts,1)[:,::-1].sum(1)
del S

def rot(d,out,seed):
    r=np.random.default_rng(seed)
    if out<=d:
        A=np.linalg.qr(r.standard_normal((d,d)))[0][:, :out]
    else:
        A=np.concatenate([np.linalg.qr(r.standard_normal((d,d)))[0] for _ in range(-(-out//d))],1)[:,:out]
    return A.astype(np.float32)

def evaluate(name,prescore_fn,scan=1.0):
    """prescore_fn(qidx array)-> (nq,N) float scores, higher=better candidate"""
    rec={n:[] for n in NPRE}; sc={n:[] for n in NPRE}
    for b in range(0,NQ,50):
        ids=np.arange(b,min(b+50,NQ))
        P=prescore_fn(ids)
        P[np.arange(len(ids)),qi[ids]]=-1e9
        P=P+rng.random(P.shape,dtype=np.float32)*0.01   # random tie-break
        top=np.argpartition(-P,MAXP,axis=1)[:,:MAXP]
        for j,q in enumerate(ids):
            o=top[j][np.argsort(-P[j,top[j]])]
            for n in NPRE:
                c=o[:n]; s=X[c]@Q[q]; k=np.argsort(-s)[:10]
                rec[n].append(len(set(c[k])&set(gt[q]))/10); sc[n].append(s[k].sum()/ideal[q])
    r={n:(round(float(np.mean(rec[n])),3),round(float(np.mean(sc[n])),3)) for n in NPRE}
    print(f'{name:38s} scan={scan:5.2f} '+' '.join(f'{n}:{r[n][0]:.2f}/{r[n][1]:.2f}' for n in NPRE),flush=True)
    return r

res={}
print(f'dataset={ds} N={N} D={D}  cell = recall@10 / score-ratio at n candidates')
print(' '*38+'            '+' '.join(f'{n:>9d}' for n in NPRE))
mu=X.mean(0)
def codes(Z): return np.where(Z>0,1,-1).astype(np.float32)
def bitmethod(name,C,Cq):
    nb=C.shape[1]
    res[name]=evaluate(name,lambda ids:Cq[ids]@C.T)
# random control
res['random']=evaluate('random control',lambda ids:rng.random((len(ids),N),dtype=np.float32))
# user's method1: raw sign of first min(D,256) dims
b=min(D,256)
bitmethod(f'xor{b} raw (current)',codes(X[:,:b]),codes(Q[:,:b]))
bitmethod(f'xor{b} centered',codes(X[:,:b]-mu[:b]),codes(Q[:,:b]-mu[:b]))
for nb in sorted({D,512,1024}):
    A=rot(D,nb,1)
    bitmethod(f'xor{nb} centered+random rotation',codes((X-mu)@A),codes((Q-mu)@A))
bitmethod(f'xor{D} raw full dims',codes(X),codes(Q)) if D>256 else None
# thermometer 3 levels per dim
sd=X.std(0); th=[-0.67,0,0.67]
def thermo(Z): return np.concatenate([codes((Z-mu)/sd-t) for t in th],1)
bitmethod(f'thermometer 3 lvls ({3*D} bits)',thermo(X),thermo(Q))
A=rot(D,3*D,2); bitmethod(f'xor{3*D} centered+rotation (same bits)',codes((X-mu)@A),codes((Q-mu)@A))
json.dump({k:{str(n):v for n,v in r.items()} for k,r in res.items()},open(f'res_{ds}.json','w'))
