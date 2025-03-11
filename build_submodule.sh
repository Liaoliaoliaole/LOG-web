#!/bin/bash
set -e
git submodule update --init --recursive

cd pecl-dbus
phpize
./configure
make -j$(nproc)
sudo make install

