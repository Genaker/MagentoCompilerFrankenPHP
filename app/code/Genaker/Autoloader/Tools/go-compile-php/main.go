package main

import (
	"flag"
	"fmt"
	"io/ioutil"
	"os"
	"path/filepath"
	"strings"
)

var (
	inputFile  = flag.String("input", "", "Input PHP file path")
	outputFile = flag.String("output", "", "Output PHP file path")
	input      = flag.String("i", "", "Input PHP file path (short)")
	output     = flag.String("o", "", "Output PHP file path (short)")
)

func main() {
	flag.Parse()

	// Handle short flags
	if *input == "" && *inputFile == "" {
		// Check positional arguments
		args := flag.Args()
		if len(args) >= 1 {
			*inputFile = args[0]
		}
		if len(args) >= 2 {
			*outputFile = args[1]
		}
	} else {
		if *input != "" {
			*inputFile = *input
		}
		if *output != "" {
			*outputFile = *output
		}
	}

	if *inputFile == "" {
		fmt.Fprintf(os.Stderr, "Error: Input file is required\n")
		fmt.Fprintf(os.Stderr, "Usage: %s [options] <input.php> [output.php]\n", os.Args[0])
		fmt.Fprintf(os.Stderr, "  -input, -i: Input PHP file path\n")
		fmt.Fprintf(os.Stderr, "  -output, -o: Output PHP file path\n")
		os.Exit(1)
	}

	// Read input file
	content, err := ioutil.ReadFile(*inputFile)
	if err != nil {
		fmt.Fprintf(os.Stderr, "Error reading input file: %v\n", err)
		os.Exit(1)
	}

	// Compile/optimize PHP content (work with bytes for better performance)
	compiled := compilePHP(content)

	// Determine output file
	outFile := *outputFile
	if outFile == "" {
		// Generate output filename from input
		ext := filepath.Ext(*inputFile)
		base := strings.TrimSuffix(*inputFile, ext)
		outFile = base + "_compiled" + ext
	}

	// Ensure output directory exists
	outputDir := filepath.Dir(outFile)
	if outputDir != "." && outputDir != "" {
		os.MkdirAll(outputDir, 0755)
	}

	// Write output file
	err = ioutil.WriteFile(outFile, compiled, 0644)
	if err != nil {
		fmt.Fprintf(os.Stderr, "Error writing output file: %v\n", err)
		os.Exit(1)
	}

	fmt.Printf("Successfully compiled: %s -> %s\n", *inputFile, outFile)
	
	// Print statistics
	inputSize := len(content)
	outputSize := len(compiled)
	compression := float64(inputSize-outputSize) / float64(inputSize) * 100
	
	fmt.Printf("Input size: %d bytes\n", inputSize)
	fmt.Printf("Output size: %d bytes\n", outputSize)
	fmt.Printf("Compression: %.2f%%\n", compression)
}

// compilePHP optimizes PHP code - optimized single-pass version
func compilePHP(content []byte) []byte {
	// Pre-allocate output buffer (estimate 80% of input size)
	output := make([]byte, 0, len(content)*80/100)
	
	// Single-pass optimization
	output = optimizePHP(content, output)
	
	return output
}

// optimizePHP performs all optimizations in a single pass
func optimizePHP(input []byte, output []byte) []byte {
	inString := false
	stringChar := byte(0)
	inSingleComment := false
	inMultiComment := false
	emptyLineCount := 0
	lastWasNewline := false
	whitespaceStart := -1
	
	i := 0
	for i < len(input) {
		char := input[i]
		
		// Handle strings first (highest priority)
		if !inString && !inSingleComment && !inMultiComment {
			if char == '"' || char == '\'' {
				inString = true
				stringChar = char
				output = append(output, char)
				i++
				continue
			}
		}
		
		if inString {
			output = append(output, char)
			// Check for escaped quote
			if char == '\\' && i+1 < len(input) {
				output = append(output, input[i+1])
				i += 2
				continue
			}
			if char == stringChar {
				inString = false
				stringChar = 0
			}
			i++
			continue
		}
		
		// Handle comments
		if !inString && !inSingleComment && !inMultiComment {
			// Check for single-line comment
			if i+1 < len(input) && char == '/' && input[i+1] == '/' {
				inSingleComment = true
				i += 2
				continue
			}
			// Check for multi-line comment
			if i+1 < len(input) && char == '/' && input[i+1] == '*' {
				inMultiComment = true
				i += 2
				continue
			}
		}
		
		if inSingleComment {
			if char == '\n' {
				inSingleComment = false
				// Keep the newline
				output = append(output, char)
				lastWasNewline = true
				emptyLineCount = 0
			}
			i++
			continue
		}
		
		if inMultiComment {
			if i+1 < len(input) && char == '*' && input[i+1] == '/' {
				inMultiComment = false
				i += 2
				continue
			}
			i++
			continue
		}
		
		// Handle whitespace optimization
		if char == ' ' || char == '\t' {
			if whitespaceStart == -1 {
				whitespaceStart = len(output)
			}
			i++
			continue
		}
		
		// Flush whitespace if needed
		if whitespaceStart != -1 {
			// Only add single space if not at line start/end
			if len(output) > 0 && output[len(output)-1] != '\n' && char != '\n' {
				output = append(output, ' ')
			}
			whitespaceStart = -1
		}
		
		// Handle newlines and empty lines
		if char == '\n' {
			if lastWasNewline {
				emptyLineCount++
				if emptyLineCount > 1 {
					// Skip excessive empty lines
					i++
					continue
				}
			} else {
				emptyLineCount = 0
			}
			output = append(output, char)
			lastWasNewline = true
			i++
			continue
		}
		
		// Regular character
		output = append(output, char)
		lastWasNewline = false
		i++
	}
	
	return output
}

// Legacy functions removed - all optimization now done in single pass optimizePHP()
