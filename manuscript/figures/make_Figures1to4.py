"""Regenerate Figures 1-4 and Supplementary Figure S1 from the deposited run data.

Reads only files that ship with this archive:
  ../../simulation/results/sim1_progress_{sp,dp,fcr}_50rep.csv   per-generation values
  ../../simulation/founder-genotypes/founder_derived_stats.csv   generation-0 baselines

Writes PNG (600 dpi), PDF and EPS for each figure, matching the formats already
supplied for Figure S2. Paths resolve relative to this script, so it runs from
any checkout location.
"""
import csv, os
import numpy as np
import matplotlib
matplotlib.use('Agg')
import matplotlib.pyplot as plt

HERE = os.path.dirname(os.path.abspath(__file__))
RESULTS = os.path.join(HERE, "..", "..", "simulation", "results")
FOUNDERS = os.path.join(HERE, "..", "..", "simulation", "founder-genotypes",
                        "founder_derived_stats.csv")

BREEDS = [("sp", "Standard Poodle"), ("dp", "Doberman Pinscher"),
          ("fcr", "Flat-Coated Retriever")]

# Strategy order, labels and colours. Labels name the strategy, not the metric.
STRATS = [("OI",     "High outlier index",              "#2c6fbb"),
          ("AGR",    "Low average genetic relatedness", "#c0392b"),
          ("IR",     "Low internal relatedness",        "#27893f"),
          ("RANDOM", "Random selection",                "#7f7f7f")]

GENS = list(range(1, 21))


def load_progress():
    """{breed: {strategy: {gen: [values per replicate]}}} for every tracked column."""
    out = {}
    for suf, _ in BREEDS:
        rows = list(csv.DictReader(open(os.path.join(RESULTS, f"sim1_progress_{suf}_50rep.csv"))))
        out[suf] = rows
    return out


def load_founders():
    rows = list(csv.DictReader(open(FOUNDERS)))
    base = {}
    for suf, _ in BREEDS:
        r = [x for x in rows if x["breed_suffix"] == suf]
        base[suf] = {
            "alleles_per_locus": np.mean([float(x["alleles_per_locus"]) for x in r]),
            "expected_heterozygosity": np.mean([float(x["expected_heterozygosity"]) for x in r]),
            "observed_heterozygosity": np.mean([float(x["observed_heterozygosity"]) for x in r]),
            "fis": np.mean([float(x["fis"]) for x in r]),
            "mean_internal_relatedness": np.mean([float(x["mean_internal_relatedness"]) for x in r]),
        }
    return base


def series(rows, strat, col):
    """Mean and SD across replicates at each generation."""
    mean, sd = [], []
    for g in GENS:
        v = [float(r[col]) for r in rows if r["strategy"] == strat and int(r["gen"]) == g]
        mean.append(np.mean(v))
        sd.append(np.std(v, ddof=1))
    return np.array(mean), np.array(sd)


def draw(figname, title, ylabel, col, founder_key, normalize=False,
         zero_line=False, shared_y=True):
    prog = load_progress()
    base = load_founders()

    plt.rcParams.update({"font.family": "DejaVu Sans", "font.size": 9,
                         "axes.edgecolor": "#444444", "axes.linewidth": 0.8})
    fig, axes = plt.subplots(1, 3, figsize=(14.4, 4.8), sharey=shared_y)

    for ax, (suf, breed) in zip(axes, BREEDS):
        rows = prog[suf]
        f0 = base[suf][founder_key]
        for strat, label, colour in STRATS:
            m, s = series(rows, strat, col)
            if normalize:
                m, s = m / f0 * 100.0, s / f0 * 100.0
                y0 = 100.0
            else:
                y0 = f0
            # prepend the generation-0 founder value so every line starts there
            x = np.array([0] + GENS)
            y = np.concatenate(([y0], m))
            e = np.concatenate(([0.0], s))
            ax.plot(x, y, color=colour, lw=1.6, label=label, zorder=3)
            ax.fill_between(x, y - e, y + e, color=colour, alpha=0.18,
                            linewidth=0, zorder=2)
        if zero_line:
            ax.axhline(0, color="#444444", lw=0.8, ls="--", zorder=1)
        ax.set_title(breed, fontsize=10, fontweight="bold")
        ax.set_xlabel("Generation")
        ax.set_xlim(0, 20)
        ax.set_xticks(range(0, 21, 5))
        ax.grid(True, color="#d9d9d9", lw=0.7, zorder=0)
        ax.set_axisbelow(True)
        for sp in ("top", "right"):
            ax.spines[sp].set_visible(False)
    axes[0].set_ylabel(ylabel)

    handles, labels = axes[0].get_legend_handles_labels()
    fig.legend(handles, labels, loc="lower center", ncol=4, frameon=False,
               fontsize=9, bbox_to_anchor=(0.5, -0.02))
    fig.suptitle(title, fontsize=10, fontweight="bold", y=0.99)
    fig.tight_layout(rect=[0, 0.04, 1, 0.96])

    for ext, kw in (("png", {"dpi": 600}), ("pdf", {}), ("eps", {})):
        fig.savefig(os.path.join(HERE, f"{figname}.{ext}"), **kw,
                    bbox_inches="tight", facecolor="white")
    plt.close(fig)
    print("written:", figname, "(png, pdf, eps)")


if __name__ == "__main__":
    draw("Figure1_allelic_richness",
         "Figure 1. Allelic richness over 20 generations (normalized to founder = 100%)",
         "Allelic richness (% of founder)", "avg_na", "alleles_per_locus",
         normalize=True)

    draw("Figure2_expected_heterozygosity",
         "Figure 2. Expected heterozygosity over 20 generations",
         "Expected heterozygosity", "avg_he", "expected_heterozygosity")

    draw("Figure3_observed_heterozygosity",
         "Figure 3. Observed heterozygosity over 20 generations",
         "Observed heterozygosity", "avg_ho", "observed_heterozygosity")

    draw("Figure4_inbreeding_coefficient",
         "Figure 4. Inbreeding coefficient (Fis) over 20 generations",
         "Inbreeding coefficient (Fis)", "avg_fis", "fis", zero_line=True)

    draw("FigureS1_mean_internal_relatedness",
         "Supplementary Figure S1. Mean population internal relatedness over 20 generations",
         "Mean internal relatedness", "avg_ir", "mean_internal_relatedness",
         zero_line=True)
