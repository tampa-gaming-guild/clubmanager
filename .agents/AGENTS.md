# Project Environment Constraints

- **Workspace Path**: The primary repository is located at `c:/apps/tgg`.
- **Git Execution**: Always run git commands using explicit git directory and worktree arguments to avoid path resolution issues:
  `git --git-dir=c:/apps/tgg/.git --work-tree=c:/apps/tgg <command>`
- **Phinx Migration Execution**: Always run Phinx migrations using the local PHP executable and config flag:
  `C:\apps\tgg\php\php.exe C:\apps\tgg\vendor\bin\phinx <command> -c C:\apps\tgg\phinx.php`
- **Git Push**: The `origin` remote has been configured with push URLs for both the personal `scott2/tgg2.git` and the Tampa Gaming Guild `tampa-gaming-guild/clubmanager` repositories. Running `git push` or `git push origin` will push to both remotes automatically.
