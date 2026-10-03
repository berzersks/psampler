#!/bin/bash
set -euo pipefail

cd /home/lotus/CLionProjects/pcg729/
# bin/spc del-download psampler
rm -rf source/php-src/ext/psampler
rm -rf source/php-src/ext/swoole
# bin/spc download psampler
rsync -a --exclude='/.git/' --exclude='/.idea/' --exclude='/cmake-build-debug/' \
  --exclude='/bench/fixtures/' --exclude='/bench/results/' --exclude='/php' \
  --exclude='/perf-pcm.data' --exclude='/perf-pcm.data.old' \
  /home/lotus/projetos/psampler/ /home/lotus/CLionProjects/pcg729/downloads/psampler/

bin/spc build --build-cli "psampler" --no-strip --enable-zts
buildroot/bin/php -v
buildroot/bin/php --ri psampler

cp buildroot/bin/php /home/lotus/projetos/psampler
