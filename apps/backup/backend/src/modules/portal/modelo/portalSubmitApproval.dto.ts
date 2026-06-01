import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';
import { IsIn, IsOptional, IsString, MinLength } from 'class-validator';

export class PortalSubmitApprovalDto {
  @ApiProperty({ example: 'ABC123' })
  @IsString()
  @MinLength(1)
  verificationCode!: string;

  @ApiProperty({ enum: ['approve', 'reject'] })
  @IsIn(['approve', 'reject'])
  decision!: 'approve' | 'reject';

  @ApiPropertyOptional({ example: '' })
  @IsOptional()
  @IsString()
  comment?: string;
}

