#!/bin/bash

cd /home/piscataway
virtualenv .
. ./bin/activate
pip install datasette datasette-cluster-map csv-to-sqlite
