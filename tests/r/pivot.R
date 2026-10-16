# Writes tests/fixtures/pivot/cases.json: inputs, specs and tidyr's output for
# pivotLongerSpec() and pivotWiderSpec(). Run from the repo root:
# Rscript tests/r/pivot.R
suppressPackageStartupMessages({ library(tidyr); library(dplyr); library(jsonlite) })
cat("tidyr", as.character(packageVersion("tidyr")), "\n")

warned <- function(expr) {
  msg <- NULL
  value <- withCallingHandlers(expr, warning = function(w) { msg <<- conditionMessage(w); invokeRestart("muffleWarning") })
  list(value = value, warning = msg)
}
case <- function(kind, data, spec, out, ..., warning = NULL) {
  list(kind = kind, data = data, spec = spec, columns = names(out), rows = out, args = list(...), warning = warning)
}

religion <- tibble(religion = c("A", "B"), `<10k` = c(1L, 4L), `10-20k` = c(2L, NA), `20-30k` = c(3L, 6L))
s1 <- build_longer_spec(religion, -religion, names_to = "income", values_to = "count")

family <- tibble(family = 1:3,
  dob_child1 = c("1998-11-26", "1996-06-22", "2002-07-11"), dob_child2 = c("2000-01-29", NA, "2004-04-05"),
  name_child1 = c("Susan", "Mark", "Sam"), name_child2 = c("Jose", NA, "Seth"))
s2 <- build_longer_spec(family, -family, names_to = c(".value", "child"), names_sep = "_")

mixed <- tibble(id = 1:2, a = c(1L, 2L), b = c(1.5, 2.5))
s3 <- build_longer_spec(mixed, -id, names_to = "k", values_to = "v")

fish <- tibble(fish = c(4842L, 4842L, 4843L, 4844L, 4843L), station = c("Release", "I80_1", "Release", "Release", "Lisbon"), seen = c(1L, 1L, 1L, 1L, 1L))
s5 <- build_wider_spec(fish, names_from = station, values_from = seen)

us <- tibble(GEOID = c("01", "01", "02", "02"), NAME = c("Alabama", "Alabama", "Alaska", "Alaska"),
  variable = c("income", "rent", "income", "rent"), estimate = c(24476, 747, 32940, 1200), moe = c(136, 3, 508, 13))
s6 <- build_wider_spec(us, names_from = variable, values_from = c(estimate, moe))

dupes <- tibble(id = c(1L, 1L, 2L), key = c("x", "x", "y"), val = c(1, 2, 3))
s7 <- build_wider_spec(dupes, names_from = key, values_from = val)
w7 <- warned(pivot_wider_spec(dupes, s7))

cases <- list(
  longer_simple = case("longer", religion, s1, pivot_longer_spec(religion, s1)),
  longer_drop_na = case("longer", religion, s1, pivot_longer_spec(religion, s1, values_drop_na = TRUE), dropNa = TRUE),
  longer_value = case("longer", family, s2, pivot_longer_spec(family, s2)),
  longer_value_drop_na = case("longer", family, s2, pivot_longer_spec(family, s2, values_drop_na = TRUE), dropNa = TRUE),
  longer_combine = case("longer", mixed, s3, pivot_longer_spec(mixed, s3)),
  wider_simple = case("wider", fish, s5, pivot_wider_spec(fish, s5)),
  wider_fill = case("wider", fish, s5, pivot_wider_spec(fish, s5, values_fill = list(seen = 0L)), valuesFill = list(seen = 0L)),
  wider_multi = case("wider", us, s6, pivot_wider_spec(us, s6)),
  wider_id_cols = case("wider", us, s6, pivot_wider_spec(us, s6, id_cols = GEOID), idColumns = "GEOID"),
  wider_dupes = case("wider", dupes, s7, w7$value, warning = w7$warning)
)
write(toJSON(cases, dataframe = "rows", na = "null", null = "null", digits = NA, auto_unbox = TRUE), "tests/fixtures/pivot/cases.json")
