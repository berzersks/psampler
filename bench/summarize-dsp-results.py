#!/usr/bin/env python3
"""Summarize the fixed A/B repetitions in the 2026-09-25 DSP study."""
import json
from pathlib import Path

results = Path(__file__).parent / "results" / "2026-09-25-dsp"
groups = {
    "spc_before": ["category-spc-before-b.jsonl", "category-spc-before-c.jsonl"],
    "spc_o3": ["category-spc-o3-b.jsonl", "category-spc-o3-c.jsonl"],
    "spc_o3_final_build": ["category-spc-final-build.jsonl"],
    "spc_o3_last_build": ["category-spc-last-build.jsonl"],
    "c_inline": ["category-core-double-c.jsonl", "category-core-double-d.jsonl"],
    "c_stage": ["category-core-stage-a.jsonl", "category-core-stage-b.jsonl"],
    "c_full_two_pass": ["category-core-full-a.jsonl", "category-core-full-b.jsonl"],
    "c_full_two_pass_zero": ["category-core-full-zero-a.jsonl", "category-core-full-zero-b.jsonl"],
    "c_all_two_pass": ["category-core-all-a.jsonl", "category-core-all-b.jsonl"],
    "c_unroll": ["category-core-unroll-a.jsonl", "category-core-unroll-b.jsonl"],
    "c_float": ["category-core-float.jsonl"],
    "c_native": ["category-core-native.jsonl"],
    "go_inline": ["category-go-double-e.jsonl", "category-go-double-f.jsonl"],
    "go_stage": ["category-go-stage-a.jsonl", "category-go-stage-b.jsonl"],
    "go_full_two_pass": ["category-go-full-a.jsonl", "category-go-full-b.jsonl"],
    "go_full_two_pass_zero": ["category-go-full-zero-a.jsonl", "category-go-full-zero-b.jsonl"],
    "go_all_two_pass": ["category-go-all-zero-a.jsonl", "category-go-all-zero-b.jsonl"],
    "go_all_two_pass_final_abba": ["category-go-all-final-a.jsonl", "category-go-all-final-b.jsonl"],
    "go_final_source": ["category-go-final-source.jsonl"],
    "go_float_inline": ["category-go-float.jsonl"],
}
fields = ("cpu_ms_per_job", "p50_ms", "p95_ms", "p99_ms", "jobs_s", "rss_peak_kb")
summary = {}
for group, files in groups.items():
    trials = [[json.loads(line) for line in (results / filename).read_text().splitlines()]
              for filename in files]
    categories = sorted({row["category"] for trial in trials for row in trial})
    by_category = {}
    for category in categories:
        rows = [row for trial in trials for row in trial if row["category"] == category]
        by_category[category] = {field: sum(row[field] for row in rows)/len(rows)
                                 for field in fields}
    summary[group] = {
        "runs": len(trials), "jobs_per_category_per_run": trials[0][0]["jobs"],
        "mean_cpu_ms_per_job_equal_categories": sum(row["cpu_ms_per_job"]
                                                      for row in by_category.values())/len(by_category),
        "by_category": by_category,
        "source_files": files,
    }
(results / "summary.json").write_text(json.dumps(summary, indent=2) + "\n")
for group, data in summary.items():
    print(f"{group:24s} {data['mean_cpu_ms_per_job_equal_categories']:.4f} ms/job")
