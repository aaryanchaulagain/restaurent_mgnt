"use client";

import { useState } from "react";
import { Button } from "@/components/ui/button";
import { FileUpload } from "@/components/ui/forms";
import { DOCUMENT_TYPES } from "../constants";
import type { RestaurantDocument } from "../types";
import { partnerApi } from "../api/partner-api";

type Props = {
  publicId: string;
  documents: RestaurantDocument[];
  onUpload: (documentType: string, file: File) => Promise<void>;
  onDelete: (documentId: number) => Promise<void>;
  disabled?: boolean;
};

export function DocumentUploader({
  publicId,
  documents,
  onUpload,
  onDelete,
  disabled,
}: Props) {
  const [uploading, setUploading] = useState<string | null>(null);
  const [pendingFiles, setPendingFiles] = useState<Record<string, File | null>>({});

  async function handleUpload(documentType: string) {
    const file = pendingFiles[documentType];
    if (!file) return;
    setUploading(documentType);
    try {
      await onUpload(documentType, file);
      setPendingFiles((prev) => ({ ...prev, [documentType]: null }));
    } finally {
      setUploading(null);
    }
  }

  return (
    <div className="space-y-6">
      {DOCUMENT_TYPES.map((docType) => {
        const existing = documents.filter((d) => d.document_type === docType.value);
        const pending = pendingFiles[docType.value];
        return (
          <div key={docType.value} className="space-y-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <h3 className="text-sm font-medium">
                {docType.label}
                {docType.required ? (
                  <span className="ml-1 text-[var(--color-error)]">*</span>
                ) : null}
              </h3>
            </div>
            {existing.map((doc) => (
              <div
                key={doc.id}
                className="flex flex-wrap items-center justify-between gap-2 rounded-[var(--radius-md)] border border-[var(--border-subtle)] bg-[var(--surface-muted)] px-3 py-2 text-sm"
              >
                <span>
                  {doc.original_name}{" "}
                  <span className="text-[var(--text-muted)]">({doc.status})</span>
                </span>
                <div className="flex gap-2">
                  <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={() =>
                      window.open(
                        partnerApi.documentDownloadUrl(publicId, doc.id),
                        "_blank",
                        "noopener,noreferrer",
                      )
                    }
                  >
                    Download
                  </Button>
                  {!disabled ? (
                    <Button
                      type="button"
                      size="sm"
                      variant="destructive"
                      onClick={() => void onDelete(doc.id)}
                    >
                      Remove
                    </Button>
                  ) : null}
                </div>
              </div>
            ))}
            {!disabled ? (
              <>
                <FileUpload
                  label={`Upload ${docType.label.toLowerCase()}`}
                  hint="PDF or image up to 5MB"
                  accept="application/pdf,image/png,image/jpeg,image/webp"
                  fileName={pending?.name}
                  onChange={(file) =>
                    setPendingFiles((prev) => ({ ...prev, [docType.value]: file }))
                  }
                />
                {pending ? (
                  <Button
                    type="button"
                    size="sm"
                    disabled={uploading === docType.value}
                    onClick={() => void handleUpload(docType.value)}
                  >
                    {uploading === docType.value ? "Uploading…" : "Upload file"}
                  </Button>
                ) : null}
              </>
            ) : null}
          </div>
        );
      })}
    </div>
  );
}
