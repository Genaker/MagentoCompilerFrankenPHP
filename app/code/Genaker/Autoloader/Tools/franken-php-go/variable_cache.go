package main

import (
	"encoding/json"
	"fmt"
	"sync"
	"time"
)

// VariableCache manages cached PHP variables with expiration
type VariableCache struct {
	mu        sync.RWMutex
	variables map[string]*CachedVariable
	ttl       time.Duration
}

// CachedVariable represents a cached variable with metadata
type CachedVariable struct {
	Value      interface{} `json:"value"`
	ExpiresAt  time.Time   `json:"expires_at"`
	AccessCount int64      `json:"access_count"`
	LastAccess time.Time   `json:"last_access"`
}

// NewVariableCache creates a new variable cache
func NewVariableCache(ttl time.Duration) *VariableCache {
	cache := &VariableCache{
		variables: make(map[string]*CachedVariable),
		ttl:       ttl,
	}
	
	// Start cleanup goroutine
	go cache.cleanup()
	
	return cache
}

// Set stores a variable in the cache
func (vc *VariableCache) Set(key string, value interface{}) {
	vc.mu.Lock()
	defer vc.mu.Unlock()
	
	vc.variables[key] = &CachedVariable{
		Value:      value,
		ExpiresAt:  time.Now().Add(vc.ttl),
		AccessCount: 0,
		LastAccess: time.Now(),
	}
}

// Get retrieves a variable from the cache
func (vc *VariableCache) Get(key string) (interface{}, bool) {
	vc.mu.RLock()
	defer vc.mu.RUnlock()
	
	cached, exists := vc.variables[key]
	if !exists {
		return nil, false
	}
	
	// Check if expired
	if time.Now().After(cached.ExpiresAt) {
		return nil, false
	}
	
	// Update access metadata
	cached.AccessCount++
	cached.LastAccess = time.Now()
	
	return cached.Value, true
}

// Delete removes a variable from the cache
func (vc *VariableCache) Delete(key string) {
	vc.mu.Lock()
	defer vc.mu.Unlock()
	delete(vc.variables, key)
}

// Clear removes all variables from the cache
func (vc *VariableCache) Clear() {
	vc.mu.Lock()
	defer vc.mu.Unlock()
	vc.variables = make(map[string]*CachedVariable)
}

// GetAllJSON returns all non-expired variables as JSON
func (vc *VariableCache) GetAllJSON() (string, error) {
	vc.mu.RLock()
	defer vc.mu.RUnlock()
	
	result := make(map[string]interface{})
	now := time.Now()
	
	for k, v := range vc.variables {
		if now.Before(v.ExpiresAt) {
			result[k] = v.Value
		}
	}
	
	jsonData, err := json.Marshal(result)
	if err != nil {
		return "", err
	}
	
	return string(jsonData), nil
}

// cleanup removes expired variables periodically
func (vc *VariableCache) cleanup() {
	ticker := time.NewTicker(1 * time.Minute)
	defer ticker.Stop()
	
	for range ticker.C {
		vc.mu.Lock()
		now := time.Now()
		for k, v := range vc.variables {
			if now.After(v.ExpiresAt) {
				delete(vc.variables, k)
			}
		}
		vc.mu.Unlock()
	}
}

// GetStats returns cache statistics
func (vc *VariableCache) GetStats() map[string]interface{} {
	vc.mu.RLock()
	defer vc.mu.RUnlock()
	
	total := len(vc.variables)
	expired := 0
	now := time.Now()
	
	for _, v := range vc.variables {
		if now.After(v.ExpiresAt) {
			expired++
		}
	}
	
	return map[string]interface{}{
		"total_variables": total,
		"expired":         expired,
		"active":          total - expired,
		"ttl_seconds":     vc.ttl.Seconds(),
	}
}

// SerializeToEnv converts cached variables to environment variable format
func (vc *VariableCache) SerializeToEnv() []string {
	vc.mu.RLock()
	defer vc.mu.RUnlock()
	
	var env []string
	now := time.Now()
	
	for k, v := range vc.variables {
		if now.Before(v.ExpiresAt) {
			// Serialize value to JSON for environment variable
			jsonValue, err := json.Marshal(v.Value)
			if err != nil {
				continue
			}
			env = append(env, fmt.Sprintf("GOGENTO_CACHE_%s=%s", k, string(jsonValue)))
		}
	}
	
	return env
}
