# Scripts

Development and deployment scripts for Secure Login Collector plugin.

**Note:** This folder is excluded from deployments (Freemius and WordPress.org). It is only for local development.

**All scripts can be run from any directory** - they auto-detect their location and use absolute paths internally.

## Scripts Overview

### Deployment Scripts

| Script | Description | Usage |
|--------|-------------|-------|
| `deploy.sh` | Main deployment script. Updates version numbers, creates git commit and tag. | `./scripts/deploy.sh` |
| `deploy-freemius-to-svn.sh` | Deploy a Freemius free ZIP to WordPress.org SVN | `./scripts/deploy-freemius-to-svn.sh [zip-path]` |

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

1. Update changelog in `readme.txt`
2. Run deployment script:
   ```bash
   ./scripts/deploy.sh
   ```
3. Push when prompted (triggers GitHub Actions → Freemius)

### Deploy to WordPress.org

After Freemius processes the release:

1. Download free version from Freemius Dashboard
2. Place ZIP in `freemius-downloads/` folder (6 levels up from plugin)
3. Run:
   ```bash
   ./scripts/deploy-freemius-to-svn.sh
   ```

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
