# Writes tests/fixtures/expr/lubridate_stringr.json: lubridate and stringr
# results for the Expr tests. Dates are "YYYY-MM-DD"; datetimes are
# "YYYY-MM-DD HH:MM:SS +hhmm" in their time zone. Run from the repo root:
# Rscript tests/r/lubridate_stringr.R
suppressPackageStartupMessages({ library(lubridate); library(stringr); library(jsonlite) })
cat("lubridate", as.character(packageVersion("lubridate")), "stringr", as.character(packageVersion("stringr")), "\n")

d <- as.Date(c("2024-01-31", "2024-02-29", "2023-12-31", NA, "2024-07-04"))
t <- ymd_hms(c("2024-01-31 23:59:59", "2024-03-10 02:30:00", "2023-12-31 12:00:00", NA, "2024-07-04 08:15:30"), tz = "UTC")
s <- c("Apple pie", "banana split", NA, "  cherry   tart  ", "apple.APPLE")

fd <- function(x) format(x, "%Y-%m-%d")
ft <- function(x) format(x, "%Y-%m-%d %H:%M:%S %z")
q <- function(expr) suppressWarnings(expr)

cases <- list(
  ymd = fd(q(ymd(c("2024-01-31", "20240229", "2023/12/31", NA, "bad")))),
  ymd_hms = ft(q(ymd_hms(c("2024-01-31 23:59:59", "2024-02-29T10:00:00", "2023/12/31 12:00", NA, "bad")))),
  dmy = fd(q(dmy(c("31/01/2024", "29-02-2024", "31.12.2023", NA, "32/01/2024")))),
  mdy = fd(q(mdy(c("01/31/2024", "02-29-2024", "12.31.2023", NA, "13/01/2024")))),
  parse_dt = ft(q(parse_date_time(c("2024-01-31 23:59:59", "31/01/2024 10:00:00", "2023-12-31 12:00:00", NA, "x"), c("Ymd HMS", "dmY HMS")))),
  year = year(d), month = month(d), day = day(d), wday = wday(d), wday_mon = wday(d, week_start = 1),
  yday = yday(d), quarter = quarter(d),
  hour = hour(t), minute = minute(t), second = second(t), year_t = year(t),
  floor_hour = ft(floor_date(t, "hour")), floor_15min = ft(floor_date(t, "15 minutes")),
  floor_week = ft(floor_date(t, "week")), floor_month_d = fd(floor_date(d, "month")),
  floor_quarter_d = fd(floor_date(d, "quarter")), floor_year_d = fd(floor_date(d, "year")),
  ceiling_month_d = fd(ceiling_date(d, "month")), ceiling_hour = ft(ceiling_date(t, "hour")),
  round_day = ft(round_date(t, "day")), round_month_d = fd(round_date(d, "month")),
  plus_days = fd(d + days(10)), plus_months = fd(d + months(1)), plus_years = fd(d + years(1)),
  minus_weeks = fd(d - weeks(2)), plus_hours = ft(t + hours(5)), plus_minutes = ft(t + minutes(90)),
  diff_days = as.numeric(d - as.Date("2024-01-01")),
  difftime_hours = as.numeric(difftime(t, ymd_hms("2024-01-01 00:00:00", tz = "UTC"), units = "hours")),
  difftime_weeks = as.numeric(difftime(d, as.Date("2023-12-31"), units = "weeks")),
  with_tz = ft(with_tz(t, "America/New_York")),
  force_tz = ft(force_tz(t, "America/New_York")),
  force_tz_epoch = as.numeric(force_tz(t, "America/New_York")),

  str_detect = str_detect(s, "a.p"),
  str_detect_fixed = str_detect(s, fixed(".")),
  str_detect_ic = str_detect(s, regex("apple", ignore_case = TRUE)),
  str_detect_negate = str_detect(s, "^a", negate = TRUE),
  str_starts = str_starts(s, "a|A"),
  str_ends = str_ends(s, "t|E"),
  str_count = str_count(s, "a"),
  str_count_fixed = str_count(s, fixed("p")),
  str_count_class = str_count(s, "[aeiou]"),
  str_extract = str_extract(s, "[a-z]+"),
  str_extract_all = lapply(str_extract_all(s, "p+"), function(x) if (length(x) == 0) list() else x),
  str_split = str_split(s, " "),
  str_split_i = str_split_i(s, " ", 2),
  str_split_i_neg = str_split_i(s, " ", -1),
  str_replace = str_replace(s, "a", "A"),
  str_replace_all = str_replace_all(s, "([aeiou])", "<\\1>"),
  str_replace_all_fixed = str_replace_all(s, fixed("."), "!"),
  str_replace_na = str_replace(s, "pie", NA_character_),
  str_pad = str_pad(c("a", "abc", NA, "abcdef", "ab"), 5),
  str_pad_right = str_pad(c("a", "abc", NA, "abcdef", "ab"), 5, side = "right", pad = "*"),
  str_pad_both = str_pad(c("a", "abc", NA, "abcdef", "ab"), 6, side = "both", pad = "-"),
  str_trunc = str_trunc(s, 8),
  str_trunc_left = str_trunc(s, 8, side = "left"),
  str_trunc_center = str_trunc(s, 9, side = "center", ellipsis = "~"),
  str_to_title = str_to_title(c("hello world", "o'neil mcdonald", "ALL CAPS", NA, "x-ray vision")),
  str_to_upper = str_to_upper(s),
  str_squish = str_squish(s),
  str_length = str_length(s)
)
write(toJSON(cases, na = "null", null = "null", digits = NA, auto_unbox = FALSE), "tests/fixtures/expr/lubridate_stringr.json")
