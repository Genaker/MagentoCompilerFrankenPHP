FROM golang:1.21-alpine AS builder

WORKDIR /build

# Copy source code
COPY main.go .

# Build the binary
RUN go build -o go-compile-php main.go

# Final stage - minimal image
FROM alpine:latest

WORKDIR /app

# Copy the binary from builder
COPY --from=builder /build/go-compile-php /app/go-compile-php

# Make it executable
RUN chmod +x /app/go-compile-php

ENTRYPOINT ["/app/go-compile-php"]
