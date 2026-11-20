# AGENTS.md — EasyLugg (Codex / Copilot / OpenCode shared)

> This file is always on. Keep it under ~200 lines. Standing rules live here,
> not in a skill. Codex reads it on every session start. If the repo already
> has an AGENTS.md, merge — do not overwrite it blindly.

## Project

EasyLugg — luggage delivery and storage, Da Nang–Hoi An. WordPress plugin
`easylugg-booking` (shortcodes `[bookingPlugin]` / `[easylugg_booking]`,
`[easylugg_tracking]`). Stack: PHP / WordPress, Elementor theme, WPML.
Local test site: http://127.0.0.1:8080. Explain in Vietnamese; code and
comments in English.

## Coding discipline — Karpathy, default surgical

Invoke `$karpathy-coding-discipline` for every code task. Four rules:

1. **Think before coding.** State assumptions explicitly. If uncertain, ask
   rather than guess. If multiple interpretations exist, present them.
   Push back when a simpler approach exists.
2. **Simplicity first.** Minimum code that solves the problem. No speculative
   features, no abstractions for single-use code, no unrequested flexibility.
3. **Surgical changes.** Touch only what the task asks. Do not "improve"
   adjacent code, comments, or formatting. Match existing style. Every
   changed line must trace directly to the request. Tidy only code you
   touched; mention unrelated dead code, do not delete it.
4. **Goal-driven execution.** State a brief plan with a verify step per step.
   Never declare a user-facing build done until the exact user-visible path
   has been exercised and the check stated. Report what was tested and what
   was not.

## Never invent — verify in code

- Never invent prices, endpoints, credentials, file paths, or decisions.
  Prices are live from `easylugg_booking_prices` (Delivery 150/180/250k VND
  by size). If the code does not contain it, say so and ask — do not guess.
- Never print API keys, passwords, tokens, or `.env` / `service.json`
  contents to output. Never commit secrets.
- Never run `git push`, `rm -rf`, or a migration without explicit approval.
  Commit before delegating a risky change so the diff can be reviewed.

## Workflow per task

- One task = one session, one scope. Give Codex exact files to read / edit
  and files it must not touch. No whole-repo dumps.
- Bugs: `$orch_fix_defect` — reproduce first, then fix, then verify.
- New feature: `$orch_add_feature` — contract first, then implement.
- API / REST (`easylugg/v1`): `$contract_first` — agree the contract
  before writing code.
- Strings (VI/EN/KO, `texts.php`): `$i18n_sync` — never hardcode user
  text, never break `esc_html__` / `esc_attr__` with a regex refactor.
- Security / payment: `$security_bounty_hunter` before touching payment,
  auth, or personal data.
- Before finishing: `php -l` on every changed PHP file, then test the real
  page on localhost:8080. Show the diff for review; do not auto-commit.

## Token discipline

- Chat mode for questions (no edits). Agent mode only for code changes.
- Reasoning: Low for quick questions, Medium default, High only for hard
  refactor after Medium has failed. High costs several times more.
- Do not re-read the whole repo each turn. Read only the files the task
  names. Do not paste large files into chat — give paths.
- If context gets long, stop, write a handoff file (done / doing / next),
  then start a fresh session from that file.
