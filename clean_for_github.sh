#!/bin/bash
#
# Script para limpar o diretório do projeto Opus PHP Extension
# e deixá-lo pronto para commit no GitHub
#
# Uso: ./clean_for_github.sh
#

set -e

echo "==================================="
echo "  Limpeza do Projeto Opus PHP"
echo "==================================="
echo ""

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Função para remover arquivo/diretório com feedback
remove_item() {
    local item="$1"
    if [ -e "$item" ]; then
        rm -rf "$item"
        echo -e "${GREEN}✓${NC} Removido: $item"
    fi
}

# Função para remover múltiplos arquivos por padrão
remove_pattern() {
    local pattern="$1"
    local count=$(find . -maxdepth 1 -name "$pattern" 2>/dev/null | wc -l)
    if [ "$count" -gt 0 ]; then
        find . -maxdepth 1 -name "$pattern" -exec rm -rf {} \;
        echo -e "${GREEN}✓${NC} Removidos $count arquivo(s): $pattern"
    fi
}

echo "1. Removendo arquivos de compilação..."
echo "--------------------------------------"

# Arquivos de build do autoconf/automake
remove_item "autom4te.cache"
remove_item "config.h"
remove_item "config.h.in"
remove_item "config.log"
remove_item "config.status"
remove_item "config.nice"
remove_item "libtool"
remove_item "Makefile"
remove_item "Makefile.fragments"
remove_item "Makefile.objects"
remove_item "configure"

# Arquivos objeto e bibliotecas compiladas
remove_pattern "*.o"
remove_pattern "*.lo"
remove_pattern "*.la"
remove_pattern "*.so"
remove_item ".libs"
remove_item "modules"

# Arquivos de dependências
remove_pattern "*.dep"

echo ""
echo "2. Removendo arquivos de teste/temporários..."
echo "--------------------------------------"

# Arquivos de áudio de teste
remove_pattern "*.pcm"
remove_pattern "*.wav"

# Logs e outputs
remove_item "valgrind.log"
remove_item "build"
remove_item "include"
remove_item ".output.txt"

# Arquivos temporários do PHP
remove_pattern "*.tmp"
remove_pattern ".*.swp"
remove_pattern "cmake-*"
remove_pattern "*~"

echo ""
echo "3. Removendo arquivos desnecessários..."
echo "--------------------------------------"

# Arquivos que não devem estar no repo
remove_item "atalhos.txt"
remove_item "s.php"

# Diretórios de IDE (opcional - comentar se quiser manter)
# remove_item ".idea"
# remove_item ".vscode"

echo ""
echo "4. Verificando estrutura essencial..."
echo "--------------------------------------"

# Verifica se arquivos essenciais existem
essential_files=(
    "php_opus.h"
    "opus.c"
    "opus_channel.c"
    "config.m4"
    "configure.ac"
    "README.md"
)

missing_files=0
for file in "${essential_files[@]}"; do
    if [ -f "$file" ]; then
        echo -e "${GREEN}✓${NC} Encontrado: $file"
    else
        echo -e "${RED}✗${NC} FALTANDO: $file"
        missing_files=$((missing_files + 1))
    fi
done

echo ""

if [ $missing_files -gt 0 ]; then
    echo -e "${RED}AVISO: $missing_files arquivo(s) essencial(is) faltando!${NC}"
    echo ""
fi

echo "5. Arquivos mantidos no projeto:"
echo "--------------------------------------"
echo "Código fonte:"
echo "  • php_opus.h"
echo "  • opus.c"
echo "  • opus_channel.c"
echo ""
echo "Build system:"
echo "  • config.m4"
echo "  • configure.ac"
echo ""
echo "Documentação:"
echo "  • README.md"
echo "  • README_NOVOS_METODOS.md"
echo "  • SECURITY.md"
echo "  • SECURITY_FIXES.md"
echo "  • AUDIO_ENHANCEMENT_TECNICO.md"
echo "  • FINAL_REPORT.md"
echo "  • FINAL_TEST_REPORT.md"
echo "  • TEST_RESULTS.md"
echo "  • SWOOLE_TESTS.md"
echo ""
echo "Testes:"
echo "  • test_*.php"
echo "  • run_all_tests.sh"
echo "  • test_build.sh"
echo "  • run-tests.php"
echo ""
echo "Exemplos:"
echo "  • example_swoole_*.php"
echo "  • exemplo_audio_enhancement.php"
echo ""
echo "Diretórios de suporte:"
echo "  • build/"
echo "  • include/"
echo "  • stubs/"
echo ""

echo "==================================="
echo -e "${GREEN}✓ Limpeza concluída!${NC}"
echo "==================================="
echo ""
echo "Próximos passos:"
echo "  1. Revisar mudanças: git status"
echo "  2. Adicionar arquivos: git add ."
echo "  3. Fazer commit: git commit -m 'mensagem'"
echo "  4. Push para GitHub: git push"
echo ""
echo -e "${YELLOW}Nota:${NC} Para recompilar, execute:"
echo "  phpize && ./configure && make"
echo ""
