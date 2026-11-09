import ast, os, re, sys, random, sysconfig
roots=[sysconfig.get_paths()['stdlib'], sysconfig.get_paths()['purelib']]
sents=set()
for root in roots:
    for dp,dn,fn in os.walk(root):
        if 'test' in dp.lower(): continue
        for f in fn:
            if not f.endswith('.py'): continue
            try:
                t=ast.parse(open(os.path.join(dp,f),encoding='utf8',errors='ignore').read())
            except Exception: continue
            for n in ast.walk(t):
                if isinstance(n,(ast.FunctionDef,ast.ClassDef,ast.Module,ast.AsyncFunctionDef)):
                    d=ast.get_docstring(n)
                    if not d: continue
                    for p in re.split(r'(?<=[.!?])\s+|\n\s*\n',d):
                        p=' '.join(p.split())
                        if 40<=len(p)<=300 and re.match(r'^[A-Za-z]',p) and p.count(' ')>=6 and '>>>' not in p and '::' not in p:
                            sents.add(p)
sents=sorted(sents); random.Random(1).shuffle(sents)
print(len(sents)); open('corpus.txt','w').write('\n'.join(sents[:30000]))
