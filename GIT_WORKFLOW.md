# Git workflow — always push to preprod

Simple rules for pushing changes. Deploy details live in `PREPROD.md`.

## The golden rule

**Always push to the `preprod` branch. Never push straight to `main`.**

`preprod` is where changes get tested. Once proven on the pre-prod site, it gets
merged into `main` for production (see `PREPROD.md` section 6).

## Every time you make a change

```sh
# 1. Make sure you're on preprod
git checkout preprod

# 2. Stage the files you changed
git add path/to/file1 path/to/file2

# 3. Commit with a clear message (see below)
git commit -m "Short summary of what changed"

# 4. Push to preprod on GitHub
git push origin preprod
```

Then go to cPanel → **Git Version Control** → **Update from Remote** to pull it
onto the pre-prod site.

## Writing a good commit message

Keep it short but say **what** changed and **why**. One line is often enough.

Format:

```
Short summary in plain English (what changed)

Optional: one or two lines on why, or anything to watch out for.
```

Good examples:

```
Make booking embed auto-size for a single event

The iframe was a fixed 640px and looked empty with one event.
It now resizes to fit its content.
```

```
Fix ticket email not sending on free orders
```

```
Add venue address to the event report export
```

Tips:
- Start with a verb: Add, Fix, Make, Remove, Update.
- Say what a person would notice, not the file names.
- Avoid vague messages like "fixes", "update", "changes", "wip".

## Checking before you push

```sh
git status        # what's changed and staged
git diff          # review the actual changes
git log --oneline -5   # recent history
```

## Going to production

Don't push to `main` directly. When a change is tested on pre-prod, merge it:

```sh
git checkout main
git merge preprod
git push origin main
git checkout preprod   # switch back so you keep working on preprod
```

See `PREPROD.md` section 6 for the full production deploy steps (backup +
migrations).
