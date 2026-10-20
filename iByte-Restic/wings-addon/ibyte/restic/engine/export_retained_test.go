package engine

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"io"
	"os"
	"path/filepath"
	"testing"
)

func TestRetainedCompressedExport(t *testing.T) {
	e, r := fixture(t)
	content := bytes.Repeat([]byte("retained-world\n"), 200)
	if err := os.WriteFile(filepath.Join(r.Root, "world.dat"), content, 0600); err != nil {
		t.Fatal(err)
	}
	mustOK(t, perform(t, e, r))
	repo, _, err := e.cfg.repository(r)
	if err != nil {
		t.Fatal(err)
	}
	hash := sha256.Sum256([]byte(repo))
	r.RepositoryFingerprint = hex.EncodeToString(hash[:])
	if err := os.RemoveAll(r.Root); err != nil {
		t.Fatal(err)
	}
	r.Operation = "download"
	r.ArchiveFormat = "tar.gz"
	if err := e.ValidateRetainedExport(r, r.Root); err != nil {
		t.Fatal(err)
	}
	var out bytes.Buffer
	if err := e.Export(context.Background(), r, &out); err != nil {
		t.Fatal(err)
	}
	gz, err := gzip.NewReader(bytes.NewReader(out.Bytes()))
	if err != nil {
		t.Fatal(err)
	}
	defer gz.Close()
	tr := tar.NewReader(gz)
	h, err := tr.Next()
	if err != nil || h.Name != "world.dat" {
		t.Fatal(h, err)
	}
	actual, err := io.ReadAll(tr)
	if err != nil || !bytes.Equal(actual, content) {
		t.Fatal("export content mismatch", err)
	}
	if _, err := tr.Next(); err != io.EOF {
		t.Fatal("unexpected extra archive entry", err)
	}
	if _, err := os.Stat(r.Root); !os.IsNotExist(err) {
		t.Fatal("export recreated deleted server")
	}
	r.RepositoryFingerprint = "incorrect"
	if e.ValidateRetainedExport(r) == nil {
		t.Fatal("wrong location accepted")
	}
	r.ArchiveFormat = "../../zip"
	if e.Export(context.Background(), r, io.Discard) == nil {
		t.Fatal("unsupported format accepted")
	}
}

func TestArchiveOutputLimitIncludesHeaders(t *testing.T) {
	root := t.TempDir()
	if err := os.WriteFile(filepath.Join(root, "empty"), nil, 0600); err != nil {
		t.Fatal(err)
	}
	var out bytes.Buffer
	if archive(root, &boundedWriter{writer: &out, remaining: 511}, 1<<20) == nil {
		t.Fatal("tar header escaped output bound")
	}
	if out.Len() > 511 {
		t.Fatal("writer exceeded hard bound")
	}
}
