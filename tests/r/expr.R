# Writes tests/fixtures/expr/expected.json: dplyr results for the Expr tests.
# Run from the repo root: Rscript tests/r/expr.R
suppressPackageStartupMessages({ library(dplyr); library(stringr); library(jsonlite) })
Sys.setlocale("LC_COLLATE", "C")

df <- tibble(
  g = c("a", "b", "a", "b", "a", "b", NA),
  x = c(3, 1, NA, 4, 1, 5, 9),
  i = c(1L, 2L, 3L, 4L, 5L, 6L, 7L),
  b = c(TRUE, NA, FALSE, TRUE, TRUE, FALSE, NA),
  s = c("Apple", "banana", NA, "cherry pie", "  pad  ", "apple", "Banana")
)

mut <- function(...) as.list(mutate(df, ...) %>% select(out))
grp <- function(...) as.list(df %>% group_by(g) %>% mutate(...) %>% ungroup() %>% select(out))
sm <- function(...) as.list(df %>% group_by(g) %>% summarise(...) %>% select(out))

cases <- list(
  arith = mut(out = x * 2 + i - 1),
  div = mut(out = i / c(1, 0, 2, 0, 5, 1, 1) ),
  mod = mut(out = c(-7, 7, -7, 7, 5.5, -5.5, 0) %% c(3, -3, -3, 3, 2, 2, 4)),
  pow = mut(out = i ^ 2),
  cmp = mut(out = x >= 3),
  and = mut(out = b & (x > 2)),
  or = mut(out = b | (x > 2)),
  not = mut(out = !b),
  is_na = mut(out = is.na(x)),
  if_else = mut(out = if_else(x > 2, "big", "small", missing = "none")),
  case_when = mut(out = case_when(x > 4 ~ "high", x > 2 ~ "mid", .default = "low")),
  coalesce = mut(out = coalesce(x, i * 10)),
  in_ = mut(out = g %in% c("a", NA)),
  between = mut(out = between(x, 1, 3)),
  na_if = mut(out = na_if(i, 3L)),
  round = mut(out = round(c(0.5, 1.5, 2.5, -0.5, 2.675, 0.125, 1234.5), c(0, 0, 0, 0, 2, 2, -1))),
  log = mut(out = log(i, base = 2)),
  str_lower = mut(out = str_to_lower(s)),
  str_length = mut(out = str_length(s)),
  str_trim = mut(out = str_trim(s)),
  str_sub = mut(out = str_sub(s, 2, -2)),
  str_sub_neg = mut(out = str_sub(s, -3)),
  str_detect = mut(out = str_detect(s, fixed("an"))),
  str_replace = mut(out = str_replace(s, fixed("a"), "A")),
  str_replace_all = mut(out = str_replace_all(s, fixed("a"), "A")),
  paste0 = mut(out = paste0(g, "-", i)),
  paste = mut(out = paste(g, x, sep = "/")),
  as_string = mut(out = as.character(x / 3)),
  lag = mut(out = lag(x)),
  lead2 = mut(out = lead(i, n = 2, default = 0L)),
  lag_order = mut(out = lag(i, order_by = x)),
  row_number = mut(out = row_number(x)),
  row_number_desc = mut(out = row_number(desc(x))),
  min_rank = mut(out = min_rank(c(2, 1, 2, NA, 3, 1, 2))),
  dense_rank = mut(out = dense_rank(c(2, 1, 2, NA, 3, 1, 2))),
  percent_rank = mut(out = percent_rank(c(2, 1, 2, NA, 3, 1, 2))),
  cume_dist = mut(out = cume_dist(c(2, 1, 2, NA, 3, 1, 2))),
  ntile = mut(out = ntile(i, 3)),
  ntile_x = mut(out = ntile(x, 2)),
  cumsum = mut(out = cumsum(c(1, 2, NA, 3, 4, 5, 6))),
  cumsum_int = mut(out = cumsum(i)),
  cummean = mut(out = cummean(i)),
  cummax = mut(out = cummax(c(1, 3, 2, 5, NA, 1, 9))),
  cumall = mut(out = cumall(c(TRUE, NA, TRUE, FALSE, TRUE, NA, TRUE))),
  cumany = mut(out = cumany(c(FALSE, NA, FALSE, TRUE, FALSE, NA, FALSE))),
  consecutive_id = mut(out = consecutive_id(c("a", "a", "b", "b", "a", NA, NA))),
  grp_mean = grp(out = x - mean(x, na.rm = TRUE)),
  grp_lag = grp(out = lag(i)),
  grp_row_number = grp(out = row_number()),
  grp_cumsum = grp(out = cumsum(i)),
  sm_n = sm(out = n()),
  sm_sum = sm(out = sum(x)),
  sm_sum_rm = sm(out = sum(x, na.rm = TRUE)),
  sm_mean = sm(out = mean(x, na.rm = TRUE)),
  sm_median = sm(out = median(x, na.rm = TRUE)),
  sm_sd = sm(out = sd(x, na.rm = TRUE)),
  sm_var = sm(out = var(i)),
  sm_min = sm(out = min(i)),
  sm_max_s = sm(out = max(s, na.rm = TRUE)),
  sm_quantile = sm(out = quantile(i, 0.3)),
  sm_iqr = sm(out = IQR(i)),
  sm_n_distinct = sm(out = n_distinct(x)),
  sm_n_distinct_rm = sm(out = n_distinct(x, na.rm = TRUE)),
  sm_first = sm(out = first(x)),
  sm_last_order = sm(out = last(s, order_by = i)),
  sm_nth = sm(out = nth(i, -2)),
  sm_any = sm(out = any(b)),
  sm_all = sm(out = all(b))
)
write(toJSON(lapply(cases, function(c) c$out), auto_unbox = FALSE, na = "null", digits = NA, null = "null"), "tests/fixtures/expr/expected.json")
