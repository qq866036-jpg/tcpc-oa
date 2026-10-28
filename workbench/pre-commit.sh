#!/usr/bin/env bash
# 中传网电子证书插件 · 提交前门禁（工作台硬门禁）
#
# 由 .githooks/pre-commit 与 .githooks/pre-merge-commit 调用；安装：bash workbench/install-hooks.sh
#
# 判据：
#   ① 暂存区为空 ⇒ 放行（无内容可提交）
#   ② 合并提交（pre-merge-commit 传 --merge，或存在 MERGE_HEAD）⇒ 不要求工作台 marker
#   ③ 普通提交 ⇒ 必须有本仓活动 marker（workbench/preflight.sh 写入），且 task/roles 完整
#   ④ 跑本仓 ci-check --precommit（①~④⑥；⑤ 留到提交后由 finish/CI 严格跑）
#
# 逃生口：git commit --no-verify（仅限事故急救；用后需补 preflight/finish 记录）。
set -euo pipefail

REPO="$(git rev-parse --show-toplevel)"
cd "$REPO"

# TASK-2270（M0b 补）：号段正则从主题仓唯一出处取（导出 ZW_TASK_ID_RE 供下方 python 使用）
# shellcheck source=workbench/task-space.sh
. "$(dirname "${BASH_SOURCE[0]}")/task-space.sh"

# --merge：由 .githooks/pre-merge-commit 传入。
# 实测 git 2.43：pre-merge-commit 执行时 MERGE_HEAD **尚未写入**，不能靠它判合并提交；
# 该钩子本身只在合并时触发，故用显式开关跳过 marker（仍跑 ci-check --precommit）。
MERGE=0
if [[ "${1:-}" == "--merge" ]]; then
  MERGE=1
fi

# ① 无暂存内容：不阻断
if git diff --cached --quiet; then
  exit 0
fi

# ② 合并提交：跳过 marker（合并提交另由分支评审/CI 把关）
if [[ "$MERGE" -eq 1 ]] || git rev-parse -q --verify MERGE_HEAD >/dev/null 2>&1; then
  echo "==> workbench 门禁：合并提交，跳过 marker 校验"
else
  GD="$(git rev-parse --absolute-git-dir)"
  MARKER="$GD/zw-workbench-active.json"
  if [[ ! -f "$MARKER" ]]; then
    cat >&2 <<'EOF'
✗ workbench 门禁：没有活动 marker，拒绝提交。

先在插件工作区跑开工门禁（任务号必须已在主题台账登记）：
    bash workbench/preflight.sh TASK-xxxx "role:触发依据;role:触发依据"

事故急救可加 git commit --no-verify，但之后必须补：
    bash workbench/preflight.sh ...   # 补开工记录
    bash workbench/finish.sh "..."   # 补收口记录
EOF
    exit 1
  fi
  python3 - "$MARKER" <<'PY'
import json, os, re, sys
path = sys.argv[1]
try:
    d = json.load(open(path, encoding="utf-8"))
except (OSError, ValueError):
    print("✗ workbench marker 不可读或不是 JSON：" + path, file=sys.stderr)
    sys.exit(1)
task = str(d.get("task", ""))
roles = str(d.get("roles_spec", ""))
# TASK-2270（M0b）：正则由 bash 侧从主题仓唯一出处取，经 ZW_TASK_ID_RE 注入
if not re.match(os.environ.get("ZW_TASK_ID_RE", r"^TASK-[0-9]{3,}$"), task):
    print(f"✗ workbench marker 的 task 非法：{task!r}", file=sys.stderr)
    sys.exit(1)
if not roles.strip():
    print("✗ workbench marker 缺 roles_spec", file=sys.stderr)
    sys.exit(1)
print(f"==> workbench 门禁：{task} / {roles[:80]}")
PY
fi

# ④ 本仓质量门（pre-commit 模式：跳过依赖 HEAD 的 ⑤）
echo "==> 跑 ci-check.sh --precommit"
bash scripts/ci-check.sh --precommit
