package main

import (
	"bytes"
	"context"
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"os/exec"
	"os/signal"
	"strings"
	"sync"
	"syscall"
	"time"
)

var (
	port      = flag.String("port", "8080", "HTTP server port")
	workers   = flag.Int("workers", 4, "Number of worker processes")
	phpBinary = flag.String("php", "php", "Path to PHP binary")
	docRoot   = flag.String("docroot", ".", "Document root directory")
)

// ApplicationState holds persistent state for worker mode
type ApplicationState struct {
	mu            sync.RWMutex
	bootstrapped  bool
	cache         *VariableCache
	lastAccess    time.Time
	requestCount  int64
}

// WorkerPool manages worker processes
type WorkerPool struct {
	workers []*Worker
	mu      sync.RWMutex
	index   int
}

// Worker represents a single worker process
type Worker struct {
	id          int
	state       *ApplicationState
	phpProcess  *PHPProcess
	requestChan chan *http.Request
	responseChan chan *http.Response
	ctx         context.Context
	cancel      context.CancelFunc
}

// PHPProcess wraps PHP execution
type PHPProcess struct {
	phpBinary string
	docRoot   string
	env       map[string]string
	// Persistent PHP process (like FrankenPHP)
	persistentProcess *exec.Cmd
	processStdin     io.WriteCloser
	processStdout    io.ReadCloser
	processMutex     sync.Mutex
	processReady     bool
}

// NewApplicationState creates a new application state
func NewApplicationState() *ApplicationState {
	return &ApplicationState{
		cache:      NewVariableCache(30 * time.Minute), // 30 minute TTL
		lastAccess: time.Now(),
	}
}

// SetVariable stores a variable in persistent state
func (as *ApplicationState) SetVariable(key string, value interface{}) {
	as.mu.Lock()
	defer as.mu.Unlock()
	as.cache.Set(key, value)
	as.lastAccess = time.Now()
}

// GetVariable retrieves a variable from persistent state
func (as *ApplicationState) GetVariable(key string) (interface{}, bool) {
	as.mu.RLock()
	defer as.mu.RUnlock()
	value, exists := as.cache.Get(key)
	if exists {
		as.lastAccess = time.Now()
	}
	return value, exists
}

// GetAllVariables returns all cached variables
func (as *ApplicationState) GetAllVariables() map[string]interface{} {
	as.mu.RLock()
	defer as.mu.RUnlock()
	result := make(map[string]interface{})
	// Get all variables from cache
	jsonData, err := as.cache.GetAllJSON()
	if err == nil {
		var vars map[string]interface{}
		if json.Unmarshal([]byte(jsonData), &vars) == nil {
			return vars
		}
	}
	return result
}

// Bootstrap marks the application as bootstrapped
func (as *ApplicationState) Bootstrap() {
	as.mu.Lock()
	defer as.mu.Unlock()
	as.bootstrapped = true
	as.lastAccess = time.Now()
}

// IsBootstrapped checks if application is bootstrapped
func (as *ApplicationState) IsBootstrapped() bool {
	as.mu.RLock()
	defer as.mu.RUnlock()
	return as.bootstrapped
}

// NewPHPProcess creates a new PHP process wrapper
func NewPHPProcess(phpBinary, docRoot string) *PHPProcess {
	return &PHPProcess{
		phpBinary: phpBinary,
		docRoot:   docRoot,
		env:       make(map[string]string),
		processReady: false,
	}
}

// StartPersistentProcess starts a persistent PHP process that stays alive
// This mimics FrankenPHP's behavior where PHP interpreter stays in memory
func (pp *PHPProcess) StartPersistentProcess(state *ApplicationState) error {
	pp.processMutex.Lock()
	defer pp.processMutex.Unlock()
	
	if pp.processReady {
		return nil // Already started
	}
	
	// For warden, we'll use PHP built-in server in persistent mode
	// This keeps PHP process alive and we send HTTP requests to it
	if strings.Contains(pp.phpBinary, "warden") || pp.phpBinary == "warden" {
		// Start PHP built-in server inside warden container
		// This will stay alive and handle requests
		serverPort := 9000 // Internal port for PHP server
		docRootPath := "/var/www/html/pub"
		
		// Start persistent PHP server via warden
		cmd := exec.Command("warden", "env", "exec", "php-fpm", "bash", "-c", 
			fmt.Sprintf("cd %s && php -S 0.0.0.0:%d -t .", docRootPath, serverPort))
		
		// Set environment
		cmd.Env = os.Environ()
		cmd.Env = append(cmd.Env, "GOGENTO_MODE=worker")
		
		// Start process in background
		cmd.Stderr = os.Stderr // Log errors
		if err := cmd.Start(); err != nil {
			return fmt.Errorf("failed to start persistent PHP process: %w", err)
		}
		
		pp.persistentProcess = cmd
		pp.processReady = true
		
		// Wait a bit for server to start
		time.Sleep(500 * time.Millisecond)
		
		log.Printf("Started persistent PHP process (PID: %d) on port %d", cmd.Process.Pid, serverPort)
		return nil
	}
	
	// For direct PHP binary, use stdin/stdout approach
	// Start PHP with -r to keep it running and read from stdin
	cmd := exec.Command(pp.phpBinary, "-r", `
		while ($line = fgets(STDIN)) {
			if (trim($line) === 'EXIT') break;
			// Process request here
			eval($line);
		}
	`)
	
	stdin, err := cmd.StdinPipe()
	if err != nil {
		return fmt.Errorf("failed to create stdin pipe: %w", err)
	}
	
	stdout, err := cmd.StdoutPipe()
	if err != nil {
		stdin.Close()
		return fmt.Errorf("failed to create stdout pipe: %w", err)
	}
	
	cmd.Env = os.Environ()
	cmd.Dir = pp.docRoot
	
	if err := cmd.Start(); err != nil {
		stdin.Close()
		stdout.Close()
		return fmt.Errorf("failed to start persistent PHP process: %w", err)
	}
	
	pp.persistentProcess = cmd
	pp.processStdin = stdin
	pp.processStdout = stdout
	pp.processReady = true
	
	log.Printf("Started persistent PHP process (PID: %d)", cmd.Process.Pid)
	return nil
}

// StopPersistentProcess stops the persistent PHP process
func (pp *PHPProcess) StopPersistentProcess() error {
	pp.processMutex.Lock()
	defer pp.processMutex.Unlock()
	
	if !pp.processReady || pp.persistentProcess == nil {
		return nil
	}
	
	if pp.processStdin != nil {
		pp.processStdin.Close()
	}
	if pp.processStdout != nil {
		pp.processStdout.Close()
	}
	
	if pp.persistentProcess.Process != nil {
		pp.persistentProcess.Process.Kill()
		pp.persistentProcess.Wait()
	}
	
	pp.processReady = false
	return nil
}

// Execute runs PHP script and returns output
func (pp *PHPProcess) Execute(scriptPath string, request *http.Request, state *ApplicationState) ([]byte, error) {
	// In a real implementation, this would use embedded PHP (libphp)
	// For now, we'll use exec to call PHP binary
	// TODO: Replace with embedded PHP library
	
	// Set environment variables from persistent state
	env := os.Environ()
	for k, v := range pp.env {
		env = append(env, fmt.Sprintf("%s=%v", k, v))
	}
	
	// Set runtime mode (always worker)
	env = append(env, "GOGENTO_MODE=worker")
	
	// Add cached variables to environment (serialized as JSON)
	if state != nil {
		state.mu.RLock()
		cacheEnv := state.cache.SerializeToEnv()
		state.mu.RUnlock()
		env = append(env, cacheEnv...)
		
		// Add cache stats
		stats := state.cache.GetStats()
		statsJSON, _ := json.Marshal(stats)
		env = append(env, fmt.Sprintf("GOGENTO_CACHE_STATS=%s", string(statsJSON)))
	}
	
	// Set HTTP request variables for Magento
	env = append(env, fmt.Sprintf("REQUEST_METHOD=%s", request.Method))
	env = append(env, fmt.Sprintf("REQUEST_URI=%s", request.URL.RequestURI()))
	env = append(env, fmt.Sprintf("QUERY_STRING=%s", request.URL.RawQuery))
	env = append(env, fmt.Sprintf("HTTP_HOST=%s", request.Host))
	env = append(env, fmt.Sprintf("SERVER_NAME=%s", request.Host))
	env = append(env, fmt.Sprintf("SERVER_PORT=%s", request.URL.Port()))
	if request.URL.Port() == "" {
		if request.URL.Scheme == "https" {
			env = append(env, "SERVER_PORT=443")
			env = append(env, "HTTPS=on")
		} else {
			env = append(env, "SERVER_PORT=80")
		}
	}
	env = append(env, fmt.Sprintf("SCRIPT_NAME=%s", request.URL.Path))
	env = append(env, fmt.Sprintf("SCRIPT_FILENAME=%s", scriptPath))
	env = append(env, "SERVER_PROTOCOL=HTTP/1.1")
	env = append(env, "GATEWAY_INTERFACE=CGI/1.1")
	
	// Set REMOTE_ADDR and REMOTE_PORT (required by Magento)
	remoteAddr := request.RemoteAddr
	// Extract IP from RemoteAddr (format: "IP:PORT")
	if strings.Contains(remoteAddr, ":") {
		remoteAddr = strings.Split(remoteAddr, ":")[0]
	}
	if remoteAddr == "" {
		remoteAddr = "127.0.0.1"
	}
	// Set REMOTE_ADDR - critical for Magento
	env = append(env, fmt.Sprintf("REMOTE_ADDR=%s", remoteAddr))
	env = append(env, "REMOTE_PORT=0")
	
	// Set HTTP headers for IP forwarding (Magento checks these)
	if request.Header.Get("X-Forwarded-For") != "" {
		env = append(env, fmt.Sprintf("HTTP_X_FORWARDED_FOR=%s", request.Header.Get("X-Forwarded-For")))
	} else {
		env = append(env, fmt.Sprintf("HTTP_X_FORWARDED_FOR=%s", remoteAddr))
	}
	
	// Set CONTENT_TYPE and CONTENT_LENGTH if present
	if request.ContentLength > 0 {
		env = append(env, fmt.Sprintf("CONTENT_LENGTH=%d", request.ContentLength))
	}
	if request.Header.Get("Content-Type") != "" {
		env = append(env, fmt.Sprintf("CONTENT_TYPE=%s", request.Header.Get("Content-Type")))
	}
	
	// Copy HTTP headers to environment (for $_SERVER in PHP)
	for key, values := range request.Header {
		headerName := strings.ToUpper(strings.ReplaceAll(key, "-", "_"))
		if len(values) > 0 {
			env = append(env, fmt.Sprintf("HTTP_%s=%s", headerName, values[0]))
		}
	}
	
	// Set X-Forwarded-* headers if needed
	if request.Header.Get("X-Forwarded-For") == "" {
		env = append(env, fmt.Sprintf("HTTP_X_FORWARDED_FOR=%s", remoteAddr))
	}
	if request.Header.Get("X-Real-IP") == "" {
		env = append(env, fmt.Sprintf("HTTP_X_REAL_IP=%s", remoteAddr))
	}
	
	// Execute PHP script using persistent process if available
	// Otherwise fall back to exec (for compatibility)
	
	// Try to use persistent process first
	if pp.processReady && strings.Contains(pp.phpBinary, "warden") {
		// Use persistent PHP server (started via StartPersistentProcess)
		// Send HTTP request to the persistent PHP server
		return pp.executeViaPersistentServer(request, scriptPath, env)
	}
	
	// Fall back to exec-based execution (original method)
	var cmd *exec.Cmd
	if strings.Contains(pp.phpBinary, "warden") || pp.phpBinary == "warden" {
		// Use warden env exec php-fpm php
		// Script path should be absolute and inside container
		// Warden maps /var/www/html to the project root
		absScriptPath := scriptPath
		if strings.HasPrefix(scriptPath, pp.docRoot) {
			// Convert host path to container path
			absScriptPath = strings.Replace(scriptPath, pp.docRoot, "/var/www/html", 1)
		} else if !strings.HasPrefix(scriptPath, "/") {
			absScriptPath = "/var/www/html/" + scriptPath
		}
		// Build environment variable string for bash
		envStr := ""
		for _, e := range env {
			envStr += "export " + e + " && "
		}
		// Execute via bash to set environment variables
		cmd = exec.Command("warden", "env", "exec", "php-fpm", "bash", "-c", envStr+"php -f "+absScriptPath)
	} else if strings.Contains(pp.phpBinary, "php-wrapper.sh") || strings.HasSuffix(pp.phpBinary, "php-wrapper.sh") {
		// Use local PHP wrapper script
		absScriptPath := scriptPath
		if !strings.HasPrefix(scriptPath, "/") {
			absScriptPath = pp.docRoot + "/" + scriptPath
		}
		cmd = exec.Command(pp.phpBinary, "-f", absScriptPath)
	} else {
		// Use direct PHP binary
		cmd = exec.Command(pp.phpBinary, "-f", scriptPath)
	}
	cmd.Env = env
	cmd.Dir = pp.docRoot
	
	// Set working directory to pub for Magento
	cmd.Dir = pp.docRoot + "/pub"
	
	// Capture both stdout and stderr
	var stdout, stderr bytes.Buffer
	cmd.Stdout = &stdout
	cmd.Stderr = &stderr
	
	err := cmd.Run()
	if err != nil {
		// Log error with full details for debugging
		errMsg := stderr.String()
		if errMsg == "" {
			errMsg = stdout.String()
		}
		log.Printf("PHP execution error: %v", err)
		log.Printf("PHP stderr: %s", stderr.String())
		log.Printf("PHP stdout: %s", stdout.String())
		return nil, fmt.Errorf("PHP execution failed: %w, stderr: %s, stdout: %s", err, stderr.String(), stdout.String())
	}
	
	return stdout.Bytes(), nil
}

// executeViaPersistentServer sends HTTP request to persistent PHP server
func (pp *PHPProcess) executeViaPersistentServer(request *http.Request, scriptPath string, env []string) ([]byte, error) {
	// Persistent PHP server is running on port 9000 inside warden container
	// We need to forward the request to it via warden
	
	// Build curl command to send request to persistent PHP server
	curlCmd := fmt.Sprintf("curl -s -X %s", request.Method)
	
	// Add Host header
	if request.Host != "" {
		curlCmd += fmt.Sprintf(" -H 'Host: %s'", request.Host)
	}
	
	// Add all request headers
	for k, v := range request.Header {
		for _, val := range v {
			// Escape single quotes in header values
			escapedVal := strings.ReplaceAll(val, "'", "'\"'\"'")
			curlCmd += fmt.Sprintf(" -H '%s: %s'", k, escapedVal)
		}
	}
	
	// Add environment variables as headers (for PHP to read from $_SERVER)
	for _, e := range env {
		parts := strings.SplitN(e, "=", 2)
		if len(parts) == 2 {
			escapedVal := strings.ReplaceAll(parts[1], "'", "'\"'\"'")
			curlCmd += fmt.Sprintf(" -H 'X-%s: %s'", parts[0], escapedVal)
		}
	}
	
	// Add request body if present
	if request.Body != nil {
		bodyBytes, err := io.ReadAll(request.Body)
		if err == nil && len(bodyBytes) > 0 {
			bodyStr := strings.ReplaceAll(string(bodyBytes), "'", "'\"'\"'")
			curlCmd += fmt.Sprintf(" -d '%s'", bodyStr)
		}
	}
	
	// Construct URL for persistent server (running inside container)
	url := fmt.Sprintf("http://localhost:9000%s", request.URL.RequestURI())
	curlCmd += " " + url
	
	// Execute curl via warden (to reach container's localhost:9000)
	cmd := exec.Command("warden", "env", "exec", "php-fpm", "bash", "-c", curlCmd)
	
	var stdout, stderr bytes.Buffer
	cmd.Stdout = &stdout
	cmd.Stderr = &stderr
	
	err := cmd.Run()
	if err != nil {
		return nil, fmt.Errorf("persistent PHP server request failed: %w, stderr: %s", err, stderr.String())
	}
	
	return stdout.Bytes(), nil
}

// NewWorker creates a new worker
func NewWorker(id int, phpProcess *PHPProcess) *Worker {
	ctx, cancel := context.WithCancel(context.Background())
	worker := &Worker{
		id:          id,
		state:       NewApplicationState(),
		phpProcess:  phpProcess,
		requestChan: make(chan *http.Request, 100),
		responseChan: make(chan *http.Response, 100),
		ctx:         ctx,
		cancel:      cancel,
	}
	
	// Start persistent PHP process for this worker (like FrankenPHP)
	// This keeps PHP interpreter alive between requests
	if err := phpProcess.StartPersistentProcess(worker.state); err != nil {
		log.Printf("Worker %d: Failed to start persistent PHP process: %v (will use exec fallback)", id, err)
	}
	
	return worker
}

// Start begins processing requests
func (w *Worker) Start() {
	go w.processRequests()
}

// processRequests handles incoming requests
func (w *Worker) processRequests() {
	for {
		select {
		case <-w.ctx.Done():
			return
		case req := <-w.requestChan:
			w.handleRequest(req)
		}
	}
}

// handleRequest processes a single HTTP request
func (w *Worker) handleRequest(req *http.Request) {
	// Bootstrap application if not already done (worker mode)
	if !w.state.IsBootstrapped() {
		// Run bootstrap script
		bootstrapScript := w.phpProcess.docRoot + "/pub/index.php"
		_, err := w.phpProcess.Execute(bootstrapScript, req, w.state)
		if err != nil {
			log.Printf("Worker %d: Bootstrap failed: %v", w.id, err)
			return
		}
		w.state.Bootstrap()
	}
	
	// Determine script to execute (Magento uses pub/index.php)
	// Always use pub/index.php for Magento
	scriptPath := w.phpProcess.docRoot + "/pub/index.php"
	
	// Execute PHP script with persistent state
	output, err := w.phpProcess.Execute(scriptPath, req, w.state)
	if err != nil {
		log.Printf("Worker %d: PHP execution failed: %v", w.id, err)
		return
	}
	
	// Create response
	response := &http.Response{
		StatusCode: http.StatusOK,
		Body:       io.NopCloser(bytes.NewReader(output)),
		Header:     make(http.Header),
	}
	response.Header.Set("Content-Type", "text/html; charset=utf-8")
	
	w.responseChan <- response
}

// Stop stops the worker
func (w *Worker) Stop() {
	w.cancel()
	// Stop persistent PHP process
	if w.phpProcess != nil {
		w.phpProcess.StopPersistentProcess()
	}
}

// NewWorkerPool creates a new worker pool
func NewWorkerPool(numWorkers int, phpProcess *PHPProcess) *WorkerPool {
	pool := &WorkerPool{
		workers: make([]*Worker, numWorkers),
	}
	
	for i := 0; i < numWorkers; i++ {
		worker := NewWorker(i, phpProcess)
		pool.workers[i] = worker
		worker.Start()
	}
	
	return pool
}

// GetWorker returns the next available worker (round-robin)
func (wp *WorkerPool) GetWorker() *Worker {
	wp.mu.Lock()
	defer wp.mu.Unlock()
	worker := wp.workers[wp.index]
	wp.index = (wp.index + 1) % len(wp.workers)
	return worker
}

// Stop stops all workers
func (wp *WorkerPool) Stop() {
	for _, worker := range wp.workers {
		worker.Stop()
	}
}

// WorkerModeHandler handles requests in worker mode (persistent state)
func WorkerModeHandler(pool *WorkerPool) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		worker := pool.GetWorker()
		
		// Send request to worker
		worker.requestChan <- r
		
		// Wait for response
		select {
		case response := <-worker.responseChan:
			// Copy headers
			for k, v := range response.Header {
				for _, val := range v {
					w.Header().Add(k, val)
				}
			}
			
			// Copy status code
			w.WriteHeader(response.StatusCode)
			
			// Copy body
			io.Copy(w, response.Body)
			response.Body.Close()
			
		case <-time.After(30 * time.Second):
			http.Error(w, "Request timeout", http.StatusRequestTimeout)
		}
	}
}

func main() {
	flag.Parse()
	
	phpProcess := NewPHPProcess(*phpBinary, *docRoot)
	
	log.Printf("Starting GoGentoServerGo in WORKER mode with %d workers", *workers)
	pool := NewWorkerPool(*workers, phpProcess)
	handler := WorkerModeHandler(pool)
	
	// Graceful shutdown
	sigChan := make(chan os.Signal, 1)
	signal.Notify(sigChan, syscall.SIGINT, syscall.SIGTERM)
	go func() {
		<-sigChan
		log.Println("Shutting down workers...")
		pool.Stop()
		os.Exit(0)
	}()
	
	server := &http.Server{
		Addr:    ":" + *port,
		Handler: handler,
	}
	
	log.Printf("GoGentoServerGo server listening on port %s", *port)
	log.Fatal(server.ListenAndServe())
}
