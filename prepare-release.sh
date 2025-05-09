#!/bin/bash
# prepare-release.sh
# Script to prepare a release by building the prefixed version and committing to a release branch

# Check if a version argument was provided
if [ $# -eq 0 ]; then
    echo "Usage: $0 <version>"
    echo "Example: $0 1.0.0"
    exit 1
fi

VERSION=$1
CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD)
RELEASE_BRANCH="release-${VERSION}"

# Ensure we're working with a clean state
if [[ -n $(git status --porcelain) ]]; then
    echo "Working directory is not clean. Please commit or stash your changes first."
    exit 1
fi

echo "Preparing release ${VERSION}..."

# Create and switch to a release branch
git checkout -b ${RELEASE_BRANCH}

# Run the build process
echo "Building prefixed version..."
php build.php

# Temporarily remove build/ from .gitignore to commit it
sed -i '' '/\/build\//d' .gitignore

# Add the build directory to git
git add build/ .gitignore
git commit -m "Add prefixed build for release ${VERSION}"

echo ""
echo "Release branch ${RELEASE_BRANCH} has been created with the prefixed build included."
echo ""
echo "Next steps:"
echo "1. Review the changes: git diff ${CURRENT_BRANCH}..${RELEASE_BRANCH}"
echo "2. Push the release branch: git push origin ${RELEASE_BRANCH}"
echo "3. Create a tag from this branch: git tag v${VERSION} && git push origin v${VERSION}"
echo "4. Packagist will automatically pull the new version"
echo ""
echo "After the release, switch back to your development branch:"
echo "git checkout ${CURRENT_BRANCH}" 