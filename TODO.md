Refs
====
- http://stackoverflow.com/questions/454734/how-can-one-change-the-timestamp-of-an-old-commit-in-git

- pre co:


    git rebase  -i HEAD~2
    git commit --amend --date=now
    git push --force (if already commit)