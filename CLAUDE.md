# Claude Code Rules

## Git identity
- Always use `git config user.name "Ajay-Singh-Adhikari"` and `git config user.email "ajaysinghadhikari697@gmail.com"` before making any commit so both author and committer are set to Ajay's credentials.
- Never set yourself (Claude) as author or co-author on any commit.
- Never add `Co-authored-by:` trailers to commit messages.
- Never include session URLs or Claude references in commit messages, PR titles, PR bodies, or code comments.

## Branches
- Never create a new branch unless explicitly asked to.
- Never create branches with the `claude/` prefix.
- When asked to make changes, always work on the branch that is currently checked out or the branch explicitly specified by the user.

## Commits and pushes
- All commits must be authored and committed under `Ajay-Singh-Adhikari <ajaysinghadhikari697@gmail.com>`.
- Do not push to any branch other than the one explicitly specified.
- Do not create pull requests unless explicitly asked.
