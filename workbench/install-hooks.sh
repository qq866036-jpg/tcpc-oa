#!/usr/bin/env bash
# 工作台 git 钩子安装/查看/卸载（tcpc-oa 工作区）
#
# 用法：
#   bash workbench/install-hooks.sh            # 安装（git config core.hooksPath .githooks）
#   bash workbench/install-hooks.sh --status   # 查看
#   bash workbench/install-hooks.sh --uninstall
#
# 说明：core.hooksPath 是**仓库级**配置（同仓所有 worktree 共用）；每个克隆各装一次。
#       逃生口：git commit --no-verify（事故急救；之后补 preflight/finish 记录）。
set -euo pipefail

REPO="$(git rev-parse --show-toplevel)"
cd "$REPO"

MODE="${1:-install}"

status() {
  echo "仓库：$REPO"
  echo "core.hooksPath = $(git config --get core.hooksPath || echo '<未设置>')"
  echo "钩子文件："
  for f in .githooks/pre-commit .githooks/pre-merge-commit .githooks/commit-msg; do
    if [[ -f "$f" ]]; then
      [[ -x "$f" ]] && echo "  ✓ $f" || echo "  ✗ $f（不可执行）"
    else
      echo "  ✗ $f（缺失）"
    fi
  done
}

case "$MODE" in
  install)
    [[ -d .githooks ]] || { echo "✗ 缺少 .githooks 目录" >&2; exit 1; }
    chmod +x .githooks/pre-commit .githooks/pre-merge-commit .githooks/commit-msg
    chmod +x workbench/pre-commit.sh workbench/commit-msg.sh
    git config core.hooksPath .githooks
    echo "✓ 已安装：core.hooksPath=.githooks"
    status
    echo
    echo "生效后：普通提交需先跑 workbench/preflight.sh（有 marker）；提交信息需含 TASK-xxxx；"
    echo "        提交前自动跑 ci-check.sh --precommit。急救可用 git commit --no-verify。"
    ;;
  --status|status)
    status
    ;;
  --uninstall|uninstall)
    git config --unset core.hooksPath || true
    echo "✓ 已卸载 core.hooksPath（.githooks 文件保留）"
    status
    ;;
  *)
    echo "用法：bash workbench/install-hooks.sh [--status|--uninstall]" >&2
    exit 2
    ;;
esac
