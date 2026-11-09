import sys, hashlib
exec(open('sim.py').read().split("res={}")[0])
mu=X.mean(0)
def codes(Z): return np.where(Z>0,1,-1).astype(np.float32)
def fwht(M):
    M=M.copy(); h=1; P=M.shape[1]
    while h<P:
        M=M.reshape(M.shape[0],-1,2,h); a=M[:,:,0,:]+M[:,:,1,:]; b=M[:,:,0,:]-M[:,:,1,:]
        M=np.stack([a,b],2).reshape(M.shape[0],P); h*=2
    return M/np.sqrt(P)
def signs(P,seed):  # deterministic from sha256, same as PHP will do
    out=[];i=0
    while len(out)<P:
        h=hashlib.sha256(f'{seed}:{i}'.encode()).digest(); i+=1
        for byte in h:
            for b in range(8): out.append(1.0 if (byte>>b)&1 else -1.0)
    return np.array(out[:P],np.float32)
def srp(Z,nbits,rounds,seed=7):
    P=1<<int(np.ceil(np.log2(Z.shape[1])))
    Zp=np.zeros((Z.shape[0],P),np.float32); Zp[:,:Z.shape[1]]=Z
    outs=[]
    for blk in range(-(-nbits//P)):
        M=Zp
        for r in range(rounds): M=fwht(M*signs(P,f'{seed}-{blk}-{r}'))
        outs.append(M)
    return np.concatenate(outs,1)[:,:nbits]
print(f'dataset={ds}'); print(' '*38+'            '+' '.join(f'{n:>9d}' for n in NPRE))
for nbits in ([512,1024] if D>256 else [256,1024]):
    for rounds in (1,2,3):
        evaluate(f'FWHT {nbits} bits, {rounds} round(s)',lambda ids,nb=nbits,r=rounds:codes(srp(Q[ids]-mu,nb,r))@codes(srp(X-mu,nb,r)).T if False else None) if False else None
Cs={}
for nbits in ([512,1024] if D>256 else [256,1024]):
    for rounds in (1,2,3):
        C=codes(srp(X-mu,nbits,rounds)); Cq=C[qi]
        evaluate(f'FWHT {nbits} bits, {rounds} round(s)',lambda ids,C=C,Cq=Cq:Cq[ids]@C.T)
if ds=='random':
    # the article's metric: top-10 includes the query itself (cos=1) + 9 neighbours, scaled by best possible
    S=Q@X.T; S[np.arange(NQ),qi]=-9
    ideal9=np.sort(np.take_along_axis(S,np.argpartition(-S,9,1)[:,:9],1),1)[:,::-1].sum(1)
    print('mean ideal sum of top-9 neighbours',ideal9.mean().round(2),' -> article scale 1+x =',(1+ideal9.mean()).round(2),'(article says ~3.18)')
    Craw=codes(X); 
    for n in (50,500,2000):
        vals=[]
        for b in range(0,NQ,50):
            ids=np.arange(b,min(b+50,NQ)); P=Craw[qi[ids]]@Craw.T; P[np.arange(len(ids)),qi[ids]]=-1e9
            P=P+rng.random(P.shape,dtype=np.float32)*0.01
            top=np.argpartition(-P,n,1)[:,:n]
            for j,q in enumerate(ids):
                s=np.sort(X[top[j]]@Q[q])[::-1][:9].sum(); vals.append((1+s)/(1+ideal9[q]))
        print(f'article-style score, xor256 raw, {n} candidates: {np.mean(vals):.3f}')
