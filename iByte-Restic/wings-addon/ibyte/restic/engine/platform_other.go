//go:build !linux

package engine

import "os"

func trustedPath(path string) error { return nil }

// Non-Linux support exists only for portable engine tests, not Wings deployment.
func privateOwner(info os.FileInfo) error         { return nil }
func freeSpace(path string, required int64) error { return nil }
func syncDir(path string) error                   { return nil }
