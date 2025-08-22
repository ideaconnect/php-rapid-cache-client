#!/bin/bash

cd /home/bartosz/dev/gryf/cache-service

echo "Testing coverage parsing..."

# Extract coverage percentages
METHODS_COVERAGE=$(grep "Methods:" coverage_output.txt | grep -oE '[0-9]+\.[0-9]+%' | head -1 | sed 's/%//')
LINES_COVERAGE=$(grep "Lines:" coverage_output.txt | grep -oE '[0-9]+\.[0-9]+%' | head -1 | sed 's/%//')

echo "Methods Coverage: ${METHODS_COVERAGE}%"
echo "Lines Coverage: ${LINES_COVERAGE}%"

# Test the awk comparison
if ! awk "BEGIN {exit !($METHODS_COVERAGE >= 100)}"; then
  echo "❌ Methods coverage is ${METHODS_COVERAGE}% - Required: 100%"
else
  echo "✅ Methods coverage OK: ${METHODS_COVERAGE}%"
fi

if ! awk "BEGIN {exit !($LINES_COVERAGE >= 100)}"; then
  echo "❌ Lines coverage is ${LINES_COVERAGE}% - Required: 100%"
else
  echo "✅ Lines coverage OK: ${LINES_COVERAGE}%"
fi
