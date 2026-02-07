.PHONY: build install clean

build:
	@echo "Building go-compile-php..."
	@go build -o go-compile-php main.go
	@echo "Build complete: ./go-compile-php"

install: build
	@echo "Installing go-compile-php..."
	@if [ -w /usr/local/bin ]; then \
		cp go-compile-php /usr/local/bin/ && echo "Installed to /usr/local/bin/go-compile-php"; \
	elif [ -d ~/go/bin ]; then \
		cp go-compile-php ~/go/bin/ && echo "Installed to ~/go/bin/go-compile-php"; \
	else \
		mkdir -p ~/go/bin && cp go-compile-php ~/go/bin/ && echo "Installed to ~/go/bin/go-compile-php"; \
	fi

install-user: build
	@echo "Installing go-compile-php to user directory..."
	@mkdir -p ~/go/bin
	@cp go-compile-php ~/go/bin/
	@echo "Installed to ~/go/bin/go-compile-php"
	@echo "Add ~/go/bin to your PATH if not already added"

clean:
	@rm -f go-compile-php
	@echo "Cleaned build artifacts"

test:
	@echo "Running tests..."
	@go test -v ./...
