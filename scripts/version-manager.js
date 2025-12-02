#!/usr/bin/env node
/**
 * Version Management Utility
 * Extracts version information from git tags and manages version across project files
 */

const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

class VersionManager {
    /**
     * Get current version from git tags
     * @returns {string} Version number (without 'v' prefix)
     */
    static getCurrentVersion() {
        try {
            const gitVersion = execSync('git describe --tags --abbrev=0', {
                encoding: 'utf8',
                stdio: ['pipe', 'pipe', 'ignore']
            }).trim();
            return gitVersion.replace(/^v/, '');
        } catch (error) {
            // Fallback to package.json
            try {
                const pkg = JSON.parse(fs.readFileSync('package.json', 'utf8'));
                return pkg.version || '0.1.0';
            } catch (e) {
                return '0.1.0-dev';
            }
        }
    }

    /**
     * Get detailed version with commit info
     * @returns {string} Detailed version string
     */
    static getDetailedVersion() {
        try {
            const version = execSync('git describe --tags --long --dirty', {
                encoding: 'utf8',
                stdio: ['pipe', 'pipe', 'ignore']
            }).trim();
            return version;
        } catch (error) {
            return this.getCurrentVersion();
        }
    }

    /**
     * Get comprehensive build information
     * @returns {Object} Build metadata object
     */
    static getBuildInfo() {
        const version = this.getCurrentVersion();
        let commit = 'unknown';
        let branch = 'unknown';
        let isDirty = false;

        try {
            commit = execSync('git rev-parse --short HEAD', {
                encoding: 'utf8'
            }).trim();

            branch = execSync('git rev-parse --abbrev-ref HEAD', {
                encoding: 'utf8'
            }).trim();

            // Check if working directory is dirty
            const status = execSync('git status --porcelain', {
                encoding: 'utf8'
            });
            isDirty = status.length > 0;
        } catch (error) {
            // Git not available or not in a git repository
        }

        const buildDate = new Date().toISOString();
        const environment = process.env.NODE_ENV || 'development';

        return {
            version,
            commit,
            branch,
            buildDate,
            environment,
            isDirty,
            fullVersion: `${version}+${commit}${isDirty ? '-dirty' : ''}`,
            nodeVersion: process.version
        };
    }

    /**
     * Parse semantic version string
     * @param {string} version - Version string to parse
     * @returns {Object} Parsed version components
     */
    static parseVersion(version) {
        const semverRegex = /^v?(\d+)\.(\d+)\.(\d+)(?:-([a-zA-Z0-9.-]+))?(?:\+([a-zA-Z0-9.-]+))?$/;
        const match = version.match(semverRegex);

        if (!match) {
            throw new Error(`Invalid semantic version: ${version}`);
        }

        return {
            major: parseInt(match[1], 10),
            minor: parseInt(match[2], 10),
            patch: parseInt(match[3], 10),
            prerelease: match[4] || null,
            build: match[5] || null,
            raw: version
        };
    }

    /**
     * Bump version number
     * @param {string} currentVersion - Current version
     * @param {string} type - Bump type: major, minor, patch
     * @param {string} prerelease - Optional prerelease identifier
     * @returns {string} New version string
     */
    static bumpVersion(currentVersion, type, prerelease = null) {
        const parsed = this.parseVersion(currentVersion);

        switch (type) {
            case 'major':
                parsed.major++;
                parsed.minor = 0;
                parsed.patch = 0;
                parsed.prerelease = null;
                break;
            case 'minor':
                parsed.minor++;
                parsed.patch = 0;
                parsed.prerelease = null;
                break;
            case 'patch':
                parsed.patch++;
                parsed.prerelease = null;
                break;
            case 'prerelease':
                if (parsed.prerelease) {
                    // Increment prerelease number
                    const parts = parsed.prerelease.split('.');
                    const lastPart = parts[parts.length - 1];
                    if (/^\d+$/.test(lastPart)) {
                        parts[parts.length - 1] = (parseInt(lastPart, 10) + 1).toString();
                        parsed.prerelease = parts.join('.');
                    } else {
                        parsed.prerelease += '.1';
                    }
                } else {
                    parsed.prerelease = prerelease || 'beta.1';
                }
                break;
            default:
                throw new Error(`Invalid bump type: ${type}`);
        }

        let newVersion = `${parsed.major}.${parsed.minor}.${parsed.patch}`;
        if (parsed.prerelease) {
            newVersion += `-${parsed.prerelease}`;
        }
        return newVersion;
    }

    /**
     * Update package.json with current git version
     */
    static updatePackageJson() {
        const version = this.getCurrentVersion();
        const pkgPath = path.join(process.cwd(), 'package.json');

        try {
            const pkg = JSON.parse(fs.readFileSync(pkgPath, 'utf8'));
            pkg.version = version;
            fs.writeFileSync(pkgPath, JSON.stringify(pkg, null, 2) + '\n');
            console.log(`✓ Updated package.json to version ${version}`);
        } catch (error) {
            console.error(`✗ Failed to update package.json: ${error.message}`);
            process.exit(1);
        }
    }

    /**
     * Generate version.js file with build info
     */
    static generateVersionFile() {
        const buildInfo = this.getBuildInfo();
        const srcDir = path.join(process.cwd(), 'src');

        // Create src directory if it doesn't exist
        if (!fs.existsSync(srcDir)) {
            fs.mkdirSync(srcDir, { recursive: true });
        }

        const content = `// Auto-generated version file - DO NOT EDIT
// Generated at: ${buildInfo.buildDate}

export const VERSION_INFO = ${JSON.stringify(buildInfo, null, 2)};

export const getVersionString = () => VERSION_INFO.fullVersion;
export const getVersion = () => VERSION_INFO.version;
export const getBuildDate = () => VERSION_INFO.buildDate;
export const getCommit = () => VERSION_INFO.commit;
export const getBranch = () => VERSION_INFO.branch;
export const getEnvironment = () => VERSION_INFO.environment;
export const isDirty = () => VERSION_INFO.isDirty;
`;

        const versionPath = path.join(srcDir, 'version.js');
        fs.writeFileSync(versionPath, content);
        console.log(`✓ Generated ${versionPath}`);
    }

    /**
     * Create a new git tag
     * @param {string} version - Version to tag
     * @param {string} message - Tag annotation message
     */
    static createTag(version, message = null) {
        const tag = version.startsWith('v') ? version : `v${version}`;
        const tagMessage = message || `Release ${tag}`;

        try {
            // Check if tag already exists
            execSync(`git rev-parse ${tag}`, { stdio: 'ignore' });
            console.error(`✗ Tag ${tag} already exists`);
            process.exit(1);
        } catch (error) {
            // Tag doesn't exist, create it
        }

        try {
            execSync(`git tag -a ${tag} -m "${tagMessage}"`, { stdio: 'inherit' });
            console.log(`✓ Created tag ${tag}`);
            console.log(`  To push: git push origin ${tag}`);
        } catch (error) {
            console.error(`✗ Failed to create tag: ${error.message}`);
            process.exit(1);
        }
    }

    /**
     * List all version tags
     * @param {number} limit - Number of tags to show
     */
    static listTags(limit = 10) {
        try {
            const tags = execSync('git tag --sort=-v:refname', {
                encoding: 'utf8'
            }).trim().split('\n').filter(Boolean);

            console.log(`\nRecent version tags (${Math.min(limit, tags.length)} of ${tags.length}):\n`);

            tags.slice(0, limit).forEach((tag, index) => {
                try {
                    const message = execSync(`git tag -l --format='%(contents:subject)' ${tag}`, {
                        encoding: 'utf8'
                    }).trim();
                    const date = execSync(`git log -1 --format=%ai ${tag}`, {
                        encoding: 'utf8'
                    }).trim().split(' ')[0];
                    console.log(`  ${index + 1}. ${tag.padEnd(20)} ${date}  ${message}`);
                } catch (e) {
                    console.log(`  ${index + 1}. ${tag}`);
                }
            });
        } catch (error) {
            console.error('✗ No tags found or not in a git repository');
        }
    }

    /**
     * Compare two versions
     * @param {string} v1 - First version
     * @param {string} v2 - Second version
     * @returns {number} -1 if v1 < v2, 0 if equal, 1 if v1 > v2
     */
    static compareVersions(v1, v2) {
        const parsed1 = this.parseVersion(v1);
        const parsed2 = this.parseVersion(v2);

        // Compare major.minor.patch
        if (parsed1.major !== parsed2.major) return parsed1.major - parsed2.major;
        if (parsed1.minor !== parsed2.minor) return parsed1.minor - parsed2.minor;
        if (parsed1.patch !== parsed2.patch) return parsed1.patch - parsed2.patch;

        // Handle prerelease versions
        if (!parsed1.prerelease && parsed2.prerelease) return 1; // release > prerelease
        if (parsed1.prerelease && !parsed2.prerelease) return -1; // prerelease < release
        if (parsed1.prerelease && parsed2.prerelease) {
            return parsed1.prerelease.localeCompare(parsed2.prerelease);
        }

        return 0;
    }
}

// CLI Interface
if (require.main === module) {
    const args = process.argv.slice(2);
    const command = args[0];

    switch (command) {
        case 'get':
            console.log(VersionManager.getCurrentVersion());
            break;

        case 'detailed':
            console.log(VersionManager.getDetailedVersion());
            break;

        case 'info':
            const info = VersionManager.getBuildInfo();
            console.log('\nVersion Information:');
            console.log('──────────────────────────────────────');
            console.log(`  Version:      ${info.version}`);
            console.log(`  Full Version: ${info.fullVersion}`);
            console.log(`  Commit:       ${info.commit}`);
            console.log(`  Branch:       ${info.branch}`);
            console.log(`  Environment:  ${info.environment}`);
            console.log(`  Build Date:   ${info.buildDate}`);
            console.log(`  Node:         ${info.nodeVersion}`);
            console.log(`  Dirty:        ${info.isDirty ? 'Yes' : 'No'}`);
            console.log('──────────────────────────────────────\n');
            break;

        case 'update':
            VersionManager.updatePackageJson();
            break;

        case 'generate':
            VersionManager.generateVersionFile();
            break;

        case 'bump':
            const type = args[1] || 'patch';
            const prerelease = args[2];
            const current = VersionManager.getCurrentVersion();
            const newVersion = VersionManager.bumpVersion(current, type, prerelease);
            console.log(`${current} → ${newVersion}`);
            break;

        case 'tag':
            const version = args[1];
            const message = args[2];
            if (!version) {
                console.error('✗ Version required: npm run version:tag <version> [message]');
                process.exit(1);
            }
            VersionManager.createTag(version, message);
            break;

        case 'list':
            const limit = parseInt(args[1], 10) || 10;
            VersionManager.listTags(limit);
            break;

        case 'compare':
            const v1 = args[1];
            const v2 = args[2];
            if (!v1 || !v2) {
                console.error('✗ Two versions required: npm run version:compare <v1> <v2>');
                process.exit(1);
            }
            const result = VersionManager.compareVersions(v1, v2);
            if (result < 0) {
                console.log(`${v1} < ${v2}`);
            } else if (result > 0) {
                console.log(`${v1} > ${v2}`);
            } else {
                console.log(`${v1} = ${v2}`);
            }
            break;

        default:
            console.log(`
Version Manager CLI

Usage:
  node scripts/version-manager.js <command> [options]

Commands:
  get                           Get current version
  detailed                      Get detailed version with commit info
  info                          Show complete build information
  update                        Update package.json with git version
  generate                      Generate src/version.js file
  bump <type> [prerelease]      Bump version (major|minor|patch|prerelease)
  tag <version> [message]       Create git tag
  list [limit]                  List version tags (default: 10)
  compare <v1> <v2>             Compare two versions

Examples:
  node scripts/version-manager.js get
  node scripts/version-manager.js bump minor
  node scripts/version-manager.js tag v1.2.0 "Release v1.2.0"
  node scripts/version-manager.js compare v1.2.0 v1.3.0
            `);
    }
}

module.exports = VersionManager;
