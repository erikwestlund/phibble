# Regenerates tests/fixtures/print/*.txt from tibble/pillar.
#
#     Rscript tests/r/print.R
#
# Each case must match the frame built by the same name in tests/PrintTest.php.

suppressPackageStartupMessages({
  library(tibble)
  library(dplyr)
})
options(width = 80, pillar.bold = FALSE, cli.num_colors = 1, crayon.enabled = FALSE)
invisible(Sys.setlocale("LC_COLLATE", "C"))

args <- commandArgs(trailingOnly = FALSE)
script <- normalizePath(sub("^--file=", "", args[grepl("^--file=", args)]))
out_dir <- file.path(dirname(dirname(script)), "fixtures", "print")
dir.create(out_dir, recursive = TRUE, showWarnings = FALSE)

save <- function(.name, .lines) {
  cat(paste(.lines, collapse = "\n"), file = file.path(out_dir, paste0(.name, ".txt")))
}
print_case <- function(.name, .x, ...) save(.name, capture.output(print(.x, ...)))
glimpse_case <- function(.name, .x) save(paste0("glimpse_", .name), capture.output(glimpse(.x)))

utc <- function(x) as.POSIXct(x, tz = "UTC")

basic <- tibble(
  a = c(1.5, 10.25, 100, NA, -3.14159),
  b = c(1L, NA, 300L, -4L, 5L),
  c = c("x", NA, "long string here", "y", "z")
)
print_case("basic", basic)
glimpse_case("basic", basic)

magnitudes <- tibble(
  big = c(1e10, 2.5e10, 3),
  small = c(1e-10, 0.5, 2),
  tiny = c(0.000123, 0.0045, 0.1)
)
print_case("magnitudes", magnitudes)
glimpse_case("magnitudes", magnitudes)

print_case("scientific", tibble(
  x = c(123456789.123, 0.001),
  y = c(1e15, 1),
  z = c(0.1, 0.25)
))

print_case("scientific_negative", tibble(
  x = c(1e-20, 1e20, NA),
  y = c(-1.5e-12, 2e8, 0)
))

print_case("rounding", tibble(
  x = c(10.25, 2.675, 9.995, 0.125, 1234.5, 99.95, 9.9996),
  y = c(-0.5, 0.05, 1/3, 2/3, 12345.678, 0.1 + 0.2, 7)
))

print_case("specials", tibble(
  x = c(Inf, -Inf, NaN, 0, NA),
  y = c(0, 0, 0, 0, 0),
  z = c(123456789L, 1L, NA, -42L, 0L)
))

print_case("many_rows", tibble(n = 1:25, s = letters[1:25]))
print_case("twenty_rows", tibble(n = 1:20))
print_case("n_rows", tibble(n = 1:5), n = 4)
print_case("thousands", tibble(id = 1:1234))

dates <- tibble(
  d = as.Date(c("2024-01-02", NA)),
  t = utc(c("2024-01-02 10:00:00", "2024-03-04 00:00:00")),
  l = c(TRUE, NA)
)
print_case("dates", dates)
glimpse_case("dates", dates)

factors <- tibble(
  f = factor(c("a", "b", NA)),
  o = factor(c("lo", "hi", "mid"), levels = c("lo", "mid", "hi"), ordered = TRUE)
)
print_case("factors", factors)
glimpse_case("factors", factors)

lists <- tibble(x = list(1:3, c(1.5, 2), "a", NULL, tibble(a = 1:2, b = 3:4, c = 5:6), list(1, "b")))
print_case("lists", lists)
glimpse_case("lists", lists)

print_case("empty", tibble())
glimpse_case("empty", tibble())
print_case("rows_no_cols", tibble(.rows = 3))
print_case("cols_no_rows", tibble(a = numeric(0), b = character(0)))
glimpse_case("cols_no_rows", tibble(a = numeric(0), b = character(0)))

wide <- as_tibble(setNames(
  lapply(1:15, function(i) round(seq(1, 3, length.out = 3) * i * 1000 + i / 7, 2)),
  paste0("measurement_", 1:15)
))
print_case("wide", wide)
glimpse_case("wide", wide)

very_wide <- as_tibble(setNames(lapply(1:120, function(i) i + 0.5), sprintf("column_number_%03d", 1:120)))
print_case("very_wide", very_wide)

long_strings <- tibble(
  s = c(
    "a very long string that goes on and on and on and on and on and on and on and on and on",
    "short"
  ),
  n = c(1, 2)
)
print_case("long_strings", long_strings)
glimpse_case("long_strings", long_strings)

print_case("ambiguous", tibble(s = c(" leading", "trailing ", "", "quote\"s", NA)))

nonsyntactic <- tibble(`my col` = 1:2, `1x` = c("a", "b"), `if` = c(TRUE, FALSE))
print_case("nonsyntactic", nonsyntactic)
glimpse_case("nonsyntactic", nonsyntactic)

long_names <- as_tibble(setNames(
  lapply(1:6, function(i) rep(strrep(letters[i], 12), 2)),
  paste0("a_really_long_column_name_", 1:6)
))
print_case("long_names", long_names)

grouped <- group_by(tibble(
  species = c("Adelie", "Adelie", "Gentoo", "Chinstrap", "Gentoo"),
  island = c("Torgersen", "Biscoe", "Biscoe", "Dream", "Biscoe"),
  mass = c(3750, 3800, 5000, NA, 5400),
  year = c(2007L, 2008L, 2007L, 2009L, 2008L)
), species)
print_case("grouped", grouped)
glimpse_case("grouped", grouped)
print_case("grouped_two", group_by(ungroup(grouped), species, year))

print_case("rowwise", rowwise(tibble(g = c("a", "b", "a"), x = 1:3)))
print_case("rowwise_id", rowwise(tibble(g = c("a", "b", "a"), x = 1:3), g))
glimpse_case("rowwise", rowwise(tibble(g = c("a", "b", "a"), x = 1:3)))

print_case("negatives", tibble(x = c(-1, -10.5, 100.25), y = c(-0.001, 0.02, -3)))

print_case("narrow", tibble(
  x = c(123.456, 7.1, 0.5),
  s = c("abcdefghijklmnopqrstuvwxyz", "b", "c"),
  t = c("ABCDEFGHIJKLMNOPQRSTUVWXYZ", "B", "C")
), width = 30)
