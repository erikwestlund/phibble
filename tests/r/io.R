# Regenerates tests/fixtures/io/expected/*.json from readr.
#
#   Rscript tests/r/io.R
#
# For each case in tests/fixtures/io/cases.json this reads the fixture with
# readr::read_delim() and records names, types, values, the number of
# parsing problems and, for write cases, readr::format_csv() output.

suppressPackageStartupMessages({
  library(readr)
  library(jsonlite)
})

args <- commandArgs(trailingOnly = FALSE)
script <- normalizePath(sub("^--file=", "", args[grep("^--file=", args)]))
root <- file.path(dirname(dirname(script)), "fixtures", "io")
cases <- fromJSON(file.path(root, "cases.json"), simplifyVector = FALSE)

type_of <- function(x) {
  if (is.factor(x)) return("factor")
  if (inherits(x, "POSIXct")) return("datetime")
  if (inherits(x, "Date")) return("date")
  if (inherits(x, "hms")) return("time")
  switch(typeof(x), logical = "bool", integer = "int", double = "float", character = "string", typeof(x))
}

values_of <- function(x) {
  out <- if (inherits(x, "POSIXct")) {
    format(x, "%Y-%m-%dT%H:%M:%OS6Z", tz = "UTC")
  } else if (inherits(x, "Date")) {
    format(x, "%Y-%m-%d")
  } else if (is.double(x)) {
    ifelse(is.nan(x), "NaN", ifelse(is.infinite(x), ifelse(x > 0, "Inf", "-Inf"), sprintf("%.17g", x)))
  } else if (is.factor(x)) {
    as.character(x)
  } else {
    x
  }
  out <- as.list(out)
  out[is.na(x) & !is.nan(x)] <- list(NULL)
  out
}

for (case in cases) {
  r <- case$r
  opts <- list(
    file = file.path(root, case$file),
    delim = if (is.null(r$delim)) "," else r$delim,
    show_col_types = FALSE,
    progress = FALSE,
    trim_ws = TRUE # read_csv()'s default; read_delim() defaults to FALSE
  )
  if (!is.null(r$decimal_mark)) opts$locale <- locale(decimal_mark = r$decimal_mark)
  if (!is.null(r$col_types_named)) {
    spec <- r$col_types_named
    default <- spec[[".default"]]
    spec[[".default"]] <- NULL
    opts$col_types <- do.call(cols, c(spec, list(.default = default)))
  }
  for (key in c("na", "skip", "n_max", "comment", "col_names", "col_types", "guess_max",
                "trim_ws", "skip_empty_rows", "name_repair", "col_select")) {
    if (!is.null(r[[key]])) {
      value <- r[[key]]
      if (is.list(value)) value <- unlist(value)
      opts[[key]] <- value
    }
  }

  d <- suppressWarnings(do.call(read_delim, opts))
  expected <- list(
    names = names(d),
    types = lapply(d, type_of),
    levels = lapply(Filter(is.factor, d), levels),
    values = lapply(d, values_of),
    problems = nrow(problems(d))
  )
  if (isTRUE(case$write)) {
    expected$csv <- format_csv(d)
    expected$csv_all <- format_csv(d, quote = "all")
    expected$csv_na <- format_csv(d, na = "")
  }
  writeLines(
    toJSON(expected, auto_unbox = TRUE, null = "null", pretty = TRUE, digits = NA),
    file.path(root, "expected", paste0(case$name, ".json"))
  )
}
cat("wrote", length(cases), "expected files\n")
