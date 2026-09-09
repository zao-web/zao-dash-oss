# Meta Ads Image Storage Configuration

## Problem

Meta Ads creative images were returning 404 errors on Laravel Cloud. This is because Laravel Cloud uses ephemeral storage - files saved to local disk don't persist across deployments or between different container instances.

## Solution

The `public` disk has been configured to support both local (for development) and S3 (for production on Laravel Cloud).

## Laravel Cloud Setup

### 1. Create an S3 Bucket

Create an AWS S3 bucket for storing Meta Ads creative images:

```bash
# Example bucket name
app-meta-ads-creatives
```

### 2. Configure AWS IAM User

Create an IAM user with S3 permissions:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": [
        "s3:PutObject",
        "s3:GetObject",
        "s3:DeleteObject",
        "s3:ListBucket"
      ],
      "Resource": [
        "arn:aws:s3:::app-meta-ads-creatives",
        "arn:aws:s3:::app-meta-ads-creatives/*"
      ]
    }
  ]
}
```

### 3. Set Environment Variables on Laravel Cloud

In your Laravel Cloud dashboard, set these environment variables:

```bash
FILESYSTEM_DISK_PUBLIC=s3
AWS_ACCESS_KEY_ID=your-access-key
AWS_SECRET_ACCESS_KEY=your-secret-key
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=app-meta-ads-creatives
```

### 4. Deploy

Push to main branch to trigger deployment:

```bash
git push origin main
```

Laravel Cloud will automatically redeploy with the new S3 configuration.

## Local Development

For local development, leave `FILESYSTEM_DISK_PUBLIC` unset or set to `local`. Images will be stored in `storage/app/public` and accessible via the storage symlink.

## Verifying Setup

After deployment, create a new Meta Ads campaign. Images should:
- Upload successfully to S3
- Be accessible via S3 URLs (e.g., `https://your-bucket.s3.amazonaws.com/meta-ads/image.jpg`)
- Display correctly in the Meta Ads dashboard

## Troubleshooting

### Images still 404ing

1. Check Laravel Cloud environment variables are set correctly
2. Verify IAM user has proper S3 permissions
3. Check Laravel logs for S3 upload errors:
   ```bash
   php artisan tail --filter="S3"
   ```

### Permission denied errors

Ensure the IAM user has `s3:PutObject` and `s3:GetObject` permissions on the bucket and all objects (`bucket/*`).

### Wrong region errors

Make sure `AWS_DEFAULT_REGION` matches your S3 bucket's region.
