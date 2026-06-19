# Project Environment Constraints

- **Workspace Path**: The primary repository is located at `c:/apps/tgg`.
- **Git Execution**: Always run git commands using explicit git directory and worktree arguments to avoid path resolution issues:
  `git --git-dir=c:/apps/tgg/.git --work-tree=c:/apps/tgg <command>`
- **Phinx Migration Execution**: Always run Phinx migrations using the local PHP executable and config flag:
  `C:\apps\tgg\php\php.exe C:\apps\tgg\vendor\bin\phinx <command> -c C:\apps\tgg\phinx.php`
