package engine

import (
	"errors"
	"os"
	"path/filepath"
	"syscall"
)

func privateOwner(info os.FileInfo) error {
	st, ok := info.Sys().(*syscall.Stat_t)
	if !ok || st.Uid != uint32(os.Geteuid()) || st.Nlink != 1 {
		return errors.New("private file must have daemon ownership and one link")
	}
	return nil
}
func trustedPath(path string) error {
	for p := filepath.Clean(path); ; p = filepath.Dir(p) {
		info, err := os.Lstat(p)
		if err != nil && !os.IsNotExist(err) {
			return err
		}
		if err == nil {
			st, ok := info.Sys().(*syscall.Stat_t)
			if !ok || (st.Uid != 0 && st.Uid != uint32(os.Geteuid())) {
				return errors.New("untrusted path owner")
			}
			if info.Mode().Perm()&0022 != 0 && !(info.IsDir() && info.Mode()&os.ModeSticky != 0 && st.Uid == 0) {
				return errors.New("writable credential/repository ancestor rejected")
			}
		}
		if filepath.Dir(p) == p {
			break
		}
	}
	return nil
}
func freeSpace(path string, required int64) error {
	var st syscall.Statfs_t
	if err := syscall.Statfs(path, &st); err != nil {
		return err
	}
	if required < 0 || uint64(required)+(64<<20) > st.Bavail*uint64(st.Bsize) {
		return errors.New("insufficient staging space (requires snapshot bytes plus 64 MiB reserve)")
	}
	return nil
}
func syncDir(path string) error {
	f, err := os.Open(filepath.Dir(path))
	if err != nil {
		return err
	}
	defer f.Close()
	return f.Sync()
}
