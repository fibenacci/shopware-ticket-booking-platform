#!/usr/bin/env sh
# Tab-completion for this repo's `make` targets — including the ones defined in
# make/*.mk includes, which the stock shell completions miss. Works in bash and
# zsh; targets are sourced live from `make list`, so new targets need no edits.
#
# Enable for the current shell:   eval "$(make completion)"
# Enable permanently:             add  source /path/to/tools/completion.sh  to ~/.zshrc or ~/.bashrc

if [ -n "$ZSH_VERSION" ]; then
    _fib_make_complete() {
        local targets
        targets="$(make list 2>/dev/null)"
        compadd -- ${(f)targets}
    }
    compdef _fib_make_complete make
elif [ -n "$BASH_VERSION" ]; then
    _fib_make_complete() {
        COMPREPLY=( $(compgen -W "$(make list 2>/dev/null)" -- "${COMP_WORDS[COMP_CWORD]}") )
    }
    complete -F _fib_make_complete make
fi
