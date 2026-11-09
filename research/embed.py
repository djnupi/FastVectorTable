import numpy as np, onnxruntime as ort, time, sys
from tokenizers import Tokenizer
base='/tmp/claude-0/-home-claude/6bbb9356-05a8-50a4-993b-557a5084a940/scratchpad/mv/mysql-vector-main/src/Nlp/'
tok=Tokenizer.from_file(base+'tokenizer.json'); tok.enable_truncation(64); tok.enable_padding()
so=ort.SessionOptions(); so.intra_op_num_threads=2
s=ort.InferenceSession(base+'model_quantized.onnx',so,providers=['CPUExecutionProvider'])
names=[i.name for i in s.get_inputs()]; print(names,flush=True)
texts=open('corpus.txt').read().split('\n')[:int(sys.argv[1])]
order=np.argsort([len(t) for t in texts]); out=np.zeros((len(texts),384),np.float32)
t0=time.time(); B=64
for k in range(0,len(texts),B):
    idx=order[k:k+B]; enc=tok.encode_batch([texts[i] for i in idx])
    ids=np.array([e.ids for e in enc],np.int64); m=np.array([e.attention_mask for e in enc],np.int64)
    feed={'input_ids':ids,'attention_mask':m}
    if 'token_type_ids' in names: feed['token_type_ids']=np.zeros_like(ids)
    h=s.run(['last_hidden_state'],feed)[0][:,0]
    out[idx]=h/np.linalg.norm(h,axis=1,keepdims=True)
    if (k//B)%20==0: print(k,round(time.time()-t0),flush=True)
np.save('emb.npy',out); open('texts.txt','w').write('\n'.join(texts)); print('done',time.time()-t0)
