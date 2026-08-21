import csv, os, numpy as np, matplotlib
matplotlib.use('Agg')
import matplotlib.pyplot as plt

# Paths are resolved relative to this script, so it runs from any checkout location.
HERE = os.path.dirname(os.path.abspath(__file__))
SRC = os.path.join(HERE, "..", "data", "figureS2", "S2_mcb_oi_agr_deposit.csv")
rows=list(csv.DictReader(open(SRC)))
x=np.array([float(r['mcb_pct']) for r in rows])
oi=np.array([float(r['oi']) for r in rows])
agr=np.array([float(r['agr']) for r in rows])

plt.rcParams.update({'font.family':'DejaVu Sans','font.size':17,
                     'axes.edgecolor':'#444444','axes.linewidth':1.0})

fig,axes=plt.subplots(1,2,figsize=(13.4,5.7))
for ax,y,lab,ylab in ((axes[0],oi,'(a)','Outlier index'),
                      (axes[1],agr,'(b)','Average genetic relatedness')):
    r=np.corrcoef(x,y)[0,1]
    ax.scatter(x,y,s=55,color='#7396b0',alpha=0.55,edgecolors='none',zorder=2)
    xs=np.linspace(x.min()-1.5,x.max()+1.5,100)
    m,b=np.polyfit(x,y,1)
    ax.plot(xs,m*xs+b,color='#c0392b',lw=3.0,zorder=3)
    ax.grid(True,color='#d9d9d9',lw=0.9,zorder=0)
    ax.set_axisbelow(True)
    for sp in ('top','right'): ax.spines[sp].set_visible(False)
    ax.set_xticks(range(10,90,10))
    ax.yaxis.set_major_locator(plt.MultipleLocator(0.1 if lab=='(a)' else 0.05))
    ax.set_xlabel('Pedigree ancestry from the\nMid-Century Bottleneck (%MCB)')
    ax.set_ylabel(ylab)
    ax.text(0.015,0.975,lab,transform=ax.transAxes,va='top',ha='left',
            fontsize=19,fontweight='bold')
    sign='+' if r>0 else '-'
    ax.text(0.985,0.985,f"r = {sign}{abs(r):.2f}   (r$^2$ = {r*r:.2f})\nn = {len(x)}",
            transform=ax.transAxes,va='top',ha='right',fontsize=16,linespacing=1.5)
    print(f"{lab} r={r:+.4f} r2={r*r:.4f}")
fig.tight_layout(pad=1.4)
for ext,kw in (('png',{'dpi':600}),('pdf',{}),('eps',{})):
    fig.savefig(os.path.join(HERE,f"FigureS2_OI_AGR_vs_MCB_2panel.{ext}"),**kw,bbox_inches='tight',facecolor='white')
print("written:", [f for f in os.listdir(HERE) if f.startswith('FigureS2')])
