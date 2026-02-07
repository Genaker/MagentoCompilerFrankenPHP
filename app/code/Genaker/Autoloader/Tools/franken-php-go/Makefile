.PHONY: build build-docker run clean

build:
	go build -o GoGentoServerGo main.go variable_cache.go

build-docker:
	docker run --rm -v $(PWD):/workspace -w /workspace golang:1.21-alpine go build -o GoGentoServerGo .

run: build
	./GoGentoServerGo -port=8080 -workers=4 -docroot=../../../../..

clean:
	rm -f GoGentoServerGo
