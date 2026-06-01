import { ApiProperty } from '@nestjs/swagger';
import { IsOptional, IsString } from 'class-validator';

export class DashboardNotesDto {
  @ApiProperty({ example: 'Mis notas...' })
  @IsString()
  content!: string;
}

export class DashboardNotesUpdateDto {
  @ApiProperty({ example: 'Mis notas...' })
  @IsOptional()
  @IsString()
  content?: string;
}

export class DashboardNotesSavedDto {
  @ApiProperty({ example: true })
  done!: true;

  @ApiProperty({ example: '12:34:56' })
  timestamp!: string;
}

