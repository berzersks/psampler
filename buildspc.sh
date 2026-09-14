#!/bin/bash

cd /home/lotus/CLionProjects/pcg729/
# bin/spc del-download psampler
rm -rf source/php-src/ext/psampler
# bin/spc download psampler
cp -r /home/lotus/projetos/psampler /home/lotus/CLionProjects/pcg729/downloads
bin/spc build --build-cli "pgsql,swoole,psampler" --enable-zts
cp buildroot/bin/php /home/lotus/projetos/psampler