#!/bin/bash
set -euo pipefail

cd /home/lotus/CLionProjects/pcg729/
# bin/spc del-download psampler
rm -rf source/php-src/ext/psampler
# bin/spc download psampler
rsync -a --exclude='/.git/' --exclude='/.idea/' --exclude='/cmake-build-debug/' \
  --exclude='/bench/fixtures/' --exclude='/bench/results/' --exclude='/php' \
  /home/lotus/projetos/psampler/ /home/lotus/CLionProjects/pcg729/downloads/psampler/

bin/spc build --build-cli "swoole,ctype,standard,filter,psampler" --no-strip --enable-zts
cp buildroot/bin/php /home/lotus/projetos/psampler
