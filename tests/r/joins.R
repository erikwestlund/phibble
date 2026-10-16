# Writes tests/fixtures/joins/expected.json: dplyr join_by results for the
# inequality, rolling and overlap join tests. Run from the repo root:
# Rscript tests/r/joins.R
suppressPackageStartupMessages({ library(dplyr); library(jsonlite) })

x <- tibble(id = 1:4, t = c(5, 10, NA, 20), g = c("a", "a", "b", "b"))
y <- tibble(t = c(4, 5, 5, 12, NA), g = c("a", "a", "a", "b", "b"), v = 1:5)
iv_x <- tibble(id = 1:3, lo = c(1, 5, 10), hi = c(4, 8, 12))
iv_y <- tibble(k = 1:3, start = c(2, 6, 11), end = c(3, 9, 20))

cases <- list(
  ge_eq = left_join(x, y, join_by(g, t >= t)),
  closest_ge = left_join(x, y, join_by(g, closest(t >= t))),
  gt_only = inner_join(x, y, join_by(t > t)),
  closest_le = left_join(x, y, join_by(g, closest(t <= t))),
  semi_lt = semi_join(x, y, join_by(t < t)),
  anti_lt = anti_join(x, y, join_by(t < t)),
  full_closest = full_join(x, y, join_by(g, closest(t >= t))),
  right_ge = right_join(x, y, join_by(g, t >= t)),
  keep_true = left_join(x, y, join_by(g, t >= t), keep = TRUE),
  na_never = left_join(x, y, join_by(g, t >= t), na_matches = "never"),
  between = inner_join(iv_x, iv_y, join_by(between(lo, start, end))),
  within = inner_join(iv_x, iv_y, join_by(within(lo, hi, start, end))),
  overlaps = inner_join(iv_x, iv_y, join_by(overlaps(lo, hi, start, end))),
  closest_gt_inner = inner_join(x, y, join_by(closest(t > t)))
)
out <- lapply(cases, function(d) list(columns = names(d), rows = d))
write(toJSON(out, dataframe = "rows", na = "null", null = "null", digits = NA, auto_unbox = TRUE), "tests/fixtures/joins/expected.json")
