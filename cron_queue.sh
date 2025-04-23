#!/bin/bash

# Change to the script directory
cd "$(dirname "$0")"

# Run the queue processor
php run_queue.php >> queue.log 2>&1 