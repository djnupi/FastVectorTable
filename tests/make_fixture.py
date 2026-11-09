"""Reference implementation of BinaryCodec in numpy (float64) -> fixture for the PHP parity test."""
import numpy as np, hashlib, json, sys
def signs(P, seed):
    out=[]; i=0
    while len(out)<P:
        h=hashlib.sha256(f'{seed}:{i}'.encode()).digest(); i+=1
        for byte in h:
            for b in range(8): out.append(1.0 if (byte>>b)&1 else -1.0)
    return np.array(out[:P])
def fwht(a):
    a=a.copy(); h=1; P=len(a)
    while h<P:
        for i in range(0,P,2*h):
            x=a[i:i+h].copy(); y=a[i+h:i+2*h].copy(); a[i:i+h]=x+y; a[i+h:i+2*h]=x-y
        h*=2
    return a
def encode(v, mean, bits, rounds, seed):
    d=len(v); P=1
    while P<d: P*=2
    x=np.zeros(P); x[:d]=np.asarray(v,float)-np.asarray(mean,float)
    out=[]
    for blk in range(-(-bits//P)):
        a=x
        for r in range(rounds): a=fwht(a*signs(P,f'{seed}-{blk}-{r}'))
        out.append(a)
    b=(np.concatenate(out)[:bits]>0).astype(np.uint8)
    return np.packbits(b,bitorder='little').tobytes().hex()
if __name__=='__main__':
    emb=np.load(sys.argv[1]).astype(np.float64); mean=emb.mean(0)
    cases=[]
    for (vecs,m,bits,rounds,seed) in [(emb[:12],mean,1024,2,'7'),
                                      (np.random.default_rng(3).standard_normal((6,256)),np.zeros(256),1024,1,'abc'),
                                      (emb[12:18,:300],mean[:300],512,3,'x')]:
        cases.append({'dims':vecs.shape[1],'bits':bits,'rounds':rounds,'seed':seed,'mean':list(map(float,m)),
                      'vectors':[list(map(float,v)) for v in vecs],
                      'codes':[encode(v,m,bits,rounds,seed) for v in vecs]})
    json.dump(cases,open('tests/codec_fixture.json','w'))
    print('fixture written',[len(c['vectors']) for c in cases])
