#!/bin/bash

# Sports Feed Laravel - Production Queue Worker Manager
# Ensures reliable queue processing with proper separation and monitoring

set -e  # Exit on any error

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
LOG_DIR="$PROJECT_ROOT/storage/logs"

# Create log directory if it doesn't exist
mkdir -p "$LOG_DIR"

echo "🚀 Starting Sports Feed Laravel Queue Workers..."
echo "📁 Project Root: $PROJECT_ROOT"
echo "📝 Log Directory: $LOG_DIR"
echo ""

# Function to check if process is running
is_process_running() {
    local pid=$1
    if ps -p "$pid" > /dev/null 2>&1; then
        return 0  # Running
    else
        return 1  # Not running
    fi
}

# Function to start a queue worker with monitoring
start_worker() {
    local worker_name=$1
    local queue_list=$2
    local connection=$3
    local log_file="$LOG_DIR/queue-${worker_name}.log"

    echo "🔄 Starting worker: $worker_name"
    echo "   📋 Queues: $queue_list"
    echo "   🔗 Connection: $connection"
    echo "   📝 Log: $log_file"

    # Start worker in background with enhanced options
    nohup php artisan queue:work "$connection" \
        --queue="$queue_list" \
        --tries=3 \
        --timeout=3600 \
        --sleep=1 \
        --max-jobs=500 \
        --memory=256 \
        --backoff=30,120,480 \
        --max-exceptions=10 \
        > "$log_file" 2>&1 &

    local worker_pid=$!
    echo "   ✅ Worker started (PID: $worker_pid)"

    # Store PID for monitoring
    echo $worker_pid > "$LOG_DIR/worker-${worker_name}.pid"

    return 0
}

# Function to monitor workers
monitor_workers() {
    echo ""
    echo "📊 Worker Status Check:"

    local workers=("import-worker" "sync-worker" "odds-worker" "default-worker")
    local all_healthy=true

    for worker in "${workers[@]}"; do
        local pid_file="$LOG_DIR/worker-${worker}.pid"

        if [[ -f "$pid_file" ]]; then
            local pid=$(cat "$pid_file")
            if is_process_running "$pid"; then
                echo "   ✅ $worker (PID: $pid) - RUNNING"
            else
                echo "   ❌ $worker (PID: $pid) - STOPPED"
                rm -f "$pid_file"
                all_healthy=false
            fi
        else
            echo "   ⚠️  $worker - NO PID FILE"
            all_healthy=false
        fi
    done

    if [[ "$all_healthy" == "true" ]]; then
        echo "   🎉 All workers healthy!"
    else
        echo "   ⚠️  Some workers may need restart"
    fi

    echo ""
}

# Function to stop all workers
stop_workers() {
    echo "🛑 Stopping all queue workers..."

    # Kill all queue workers
    pkill -f "php artisan queue:work" || true

    # Clean up PID files
    rm -f "$LOG_DIR"/worker-*.pid

    echo "✅ All workers stopped"
}

# Handle script arguments
case "${1:-start}" in
    "start")
        echo "Starting workers..."

        # Stop any existing workers first
        stop_workers

        # Start workers with strategic queue grouping
        # 1. Import worker - single queue, long-running jobs
        start_worker "import-worker" "import" "redis-import"

        # 2. Sync worker - related queues, medium priority
        start_worker "sync-worker" "prematch-sync,live-sync,enrichment" "redis-sync"

        # 3. Odds worker - single queue, high frequency
        start_worker "odds-worker" "odds-sync" "redis-odds"

        # 4. Default worker - catch-all for any other jobs
        start_worker "default-worker" "default" "redis"

        echo ""
        monitor_workers

        echo "🎯 Queue Processing Strategy:"
        echo "   • Import Worker: Handles bulk data imports (long-running)"
        echo "   • Sync Worker: Processes match/live data sync (medium priority)"
        echo "   • Odds Worker: Manages odds updates (high frequency)"
        echo "   • Default Worker: Catches miscellaneous jobs"
        echo ""
        echo "📋 Management Commands:"
        echo "   • Status:  $0 status"
        echo "   • Stop:    $0 stop"
        echo "   • Restart: $0 restart"
        echo "   • Logs:    tail -f storage/logs/queue-*.log"
        ;;

    "stop")
        stop_workers
        ;;

    "status")
        monitor_workers
        ;;

    "restart")
        echo "🔄 Restarting workers..."
        stop_workers
        sleep 2
        exec "$0" start
        ;;

    *)
        echo "Usage: $0 {start|stop|status|restart}"
        echo ""
        echo "Commands:"
        echo "  start   - Start all queue workers"
        echo "  stop    - Stop all queue workers"
        echo "  status  - Check worker status"
        echo "  restart - Restart all queue workers"
        exit 1
        ;;
esac

