set -euo pipefail
source "$(dirname "$0")/../foreign-lib/repo.sh"
NO_PROFILE=1 make_repo
write_project_file
