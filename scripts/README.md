# Scripts

Development and deployment scripts for Secure Login Collector plugin.

**Note:** This folder is excluded from deployments (Freemius and WordPress.org). It is only for local development.

**All scripts can be run from any directory** - they auto-detect their location and use absolute paths internally.

## Scripts Overview

### Deployment Scripts

| Script | Description | Usage |
|--------|-------------|-------|
| `deploy.sh` | Release cutter. Guards, bumps all three version references, commits, tags, pushes `main` with the tag. | `./scripts/deploy.sh <X.Y.Z> [--dry-run] [--yes]` |
| `deploy-freemius-to-svn.sh` | Deploy a Freemius free ZIP to WordPress.org SVN (checks version consistency) | `./scripts/deploy-freemius-to-svn.sh [zip-path] [--dry-run]` |

### SVN Scripts (WordPress.org)

| Script | Description | Usage |
|--------|-------------|-------|
| `svn-initial-deploy.sh` | First-time deployment to WordPress.org | `./scripts/svn-initial-deploy.sh` |
| `svn-update.sh` | Update existing WordPress.org listing | `./scripts/svn-update.sh` |
| `svn-push.sh` | Push manual SVN changes (assets, readme fixes) | `./scripts/svn-push.sh` |
| `svn-repair-tag.sh` | Fix partially uploaded SVN tags | `./scripts/svn-repair-tag.sh` |

### Build Scripts

| Script | Description | Usage |
|--------|-------------|-------|
| `build-free-version.sh` | Build free version locally (excludes premium files) | `./scripts/build-free-version.sh` |
| `simulate-free-build.sh` | Deploy free build to test WordPress installation | `./scripts/simulate-free-build.sh` |

### Utility Scripts

| Script | Description | Usage |
|--------|-------------|-------|
| `get-version.sh` | Get current version from git tags | `./scripts/get-version.sh` |
| `download-freemius-free.sh` | Download free version from Freemius API | `./scripts/download-freemius-free.sh <version>` |
| `version-manager.js` | Node.js version management utility | `node scripts/version-manager.js <command>` |

## Typical Workflows

### Release a New Version

What ships is defined once in `.distignore` (used by the GitHub workflow and the build scripts).

1. Add the changelog entry `= X.Y.Z =` to `readme.txt`, regenerate translations if strings changed, commit.
2. Preview the release (changes nothing):
   ```bash
   ./scripts/deploy.sh 2.1.0 --dry-run
   ```
3. Cut it:
   ```bash
   ./scripts/deploy.sh 2.1.0
   ```
   The script refuses to run on a dirty tree, off `main`, behind origin, with an existing tag,
   a version that is not greater, or without a changelog entry. It bumps the plugin header,
   `SECULOCO_VERSION` and `Stable tag`, commits `Release version X.Y.Z`, tags `vX.Y.Z` and
   pushes `main`, then the tag `vX.Y.Z`.
4. The tag push runs `.github/workflows/freemius-deploy.yml`: it fails if tag, header,
   `SECULOCO_VERSION`, `Stable tag` and changelog disagree, then uploads the package to
   Freemius as "pending".
5. Release the version in the Freemius dashboard.

### Deploy to WordPress.org

After Freemius processes the release:

1. Download the free version from the Freemius Dashboard into `freemius-downloads/` (6 levels up from plugin)
2. Check it first (changes nothing):
   ```bash
   ./scripts/deploy-freemius-to-svn.sh --dry-run
   ```
3. Deploy:
   ```bash
   ./scripts/deploy-freemius-to-svn.sh
   ```
   The script aborts when header, `SECULOCO_VERSION`, `Stable tag` and changelog disagree;
   it no longer rewrites `Stable tag` silently.

### Test Free Version Locally

```bash
./scripts/simulate-free-build.sh
```

This builds the free version and deploys it to a test WordPress installation.

## Directory Structure

Scripts expect these directories relative to the dev environment root (6 levels up from scripts folder):

```
dev-root/
├── svn/                  # WordPress.org SVN checkout
├── freemius-downloads/   # Downloaded Freemius ZIPs
├── build/                # Build output
│   └── wordpress-org/    # Free version builds
└── app/public/wp-content/plugins/
    └── secure-login-collector/  # Plugin (git repo)
        └── scripts/             # This folder
```

## Version Manager Commands

```bash
node scripts/version-manager.js get        # Get current version
node scripts/version-manager.js info       # Show build info
node scripts/version-manager.js bump patch # Calculate next patch version
node scripts/version-manager.js list       # List recent tags
```
